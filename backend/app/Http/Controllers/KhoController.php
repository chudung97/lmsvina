<?php

namespace App\Http\Controllers;

use App\Support\KhoInventory;
use App\Support\OrganizationAccess;
use App\Support\WorkdeskBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nền chung cho các API quản lý kho HR-GA (/api/kho/...).
 *
 * Quyền kho được lưu riêng trong kho_staff: manager (toàn quyền, thiết lập, duyệt PO,
 * hủy giao dịch đã ghi sổ), keeper (thủ kho: nhập/xuất, lập PO) và viewer (chỉ xem).
 * Admin hệ thống và CEO luôn có quyền manager.
 */
abstract class KhoController extends ApiController
{
    protected const ITEM_GROUPS = ['Văn phòng phẩm', 'Vật tư máy in', 'Đồng phục', 'Bảo hộ lao động'];

    protected const STAFF_ROLES = ['manager', 'keeper', 'viewer'];

    private const ACCESS_LEVELS = [
        'view' => ['manager', 'keeper', 'viewer'],
        'write' => ['manager', 'keeper'],
        'manage' => ['manager'],
    ];

    private static ?array $userColumns = null;

    protected function khoRole(object $user): ?string
    {
        if (OrganizationAccess::isOrganizationAdmin($user)) {
            return 'manager';
        }
        $role = DB::table('kho_staff')->where('user_id', $user->id)->value('staff_role');

        return in_array($role, self::STAFF_ROLES, true) ? $role : null;
    }

    protected function denyUnless(Request $request, string $level): ?JsonResponse
    {
        $role = $this->khoRole($this->user($request));
        if ($role !== null && in_array($role, self::ACCESS_LEVELS[$level], true)) {
            return null;
        }

        return $this->error(match ($level) {
            'manage' => 'Chỉ quản lý kho mới được thực hiện thao tác này.',
            'write' => 'Bạn chỉ có quyền xem dữ liệu kho.',
            default => 'Bạn chưa được cấp quyền truy cập quản lý kho.',
        }, 403);
    }

    protected function today(): string
    {
        return Carbon::now('Asia/Ho_Chi_Minh')->toDateString();
    }

    protected function nowTime(): string
    {
        return Carbon::now('Asia/Ho_Chi_Minh')->format('H:i:s');
    }

    protected function settings(): array
    {
        $settings = KhoInventory::DEFAULT_SETTINGS;
        foreach (DB::table('kho_settings')->get(['setting_key', 'setting_value']) as $row) {
            if (array_key_exists($row->setting_key, $settings)) {
                $value = json_decode($row->setting_value, true);
                $settings[$row->setting_key] = $value ?? $settings[$row->setting_key];
            }
        }

        return $settings;
    }

    protected function refDate(array $settings): string
    {
        $ref = (string) ($settings['refDate'] ?? '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) ? $ref : $this->today();
    }

    protected function userColumns(): array
    {
        if (self::$userColumns === null) {
            self::$userColumns = [
                'hire' => Schema::hasColumn('users', 'NgayVaoLamViec'),
                'leave' => Schema::hasColumn('users', 'NghiViecTamThoi'),
            ];
        }

        return self::$userColumns;
    }

    protected function loadEmployees(): array
    {
        $columns = ['users.id', 'users.user_code', 'users.name', 'users.department_id', 'departments.name as department_name',
            'kho_employee_sizes.shirt_size', 'kho_employee_sizes.pants_size', 'kho_employee_sizes.shoe_size'];
        $userColumns = $this->userColumns();
        if ($userColumns['hire']) {
            $columns[] = 'users.NgayVaoLamViec as hire_date';
        }
        if ($userColumns['leave']) {
            $columns[] = 'users.NghiViecTamThoi as on_leave';
        }

        return DB::table('users')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->leftJoin('kho_employee_sizes', 'kho_employee_sizes.user_id', '=', 'users.id')
            ->where('users.active', 1)
            ->orderBy('users.name')
            ->get($columns)
            ->map(fn (object $user): array => [
                'id' => (int) $user->id,
                'code' => (string) ($user->user_code ?? ''),
                'name' => (string) $user->name,
                'departmentId' => $user->department_id !== null ? (string) $user->department_id : null,
                'departmentName' => (string) ($user->department_name ?? ''),
                'hireDate' => ! empty($user->hire_date) ? substr((string) $user->hire_date, 0, 10) : '',
                'onLeave' => (bool) ($user->on_leave ?? false),
                'working' => ! ($user->on_leave ?? false),
                'shirtSize' => (string) ($user->shirt_size ?? ''),
                'pantsSize' => (string) ($user->pants_size ?? ''),
                'shoeSize' => (string) ($user->shoe_size ?? ''),
            ])
            ->all();
    }

    protected function loadItems(): array
    {
        return DB::table('kho_items')->orderBy('code')->get()->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'group' => $row->item_group,
            'unit' => $row->unit,
            'color' => $row->color,
            'size' => $row->size,
            'spec' => $row->spec,
            'supplier' => $row->supplier,
            'price' => (float) $row->unit_price,
            'min' => (int) $row->min_qty,
            'max' => (int) $row->max_qty,
            'lead' => (int) $row->lead_days,
            'cycle' => (int) $row->cycle_months,
            'perPerson' => (int) $row->qty_per_person,
            'moq' => (int) $row->moq,
            'opening' => (int) $row->opening_qty,
            'family' => $row->family_code,
            'note' => $row->note,
            'active' => (bool) $row->active,
        ])->all();
    }

    /** Nạp toàn bộ dữ liệu cần cho KhoInventory::compute. */
    protected function loadData(): array
    {
        $receipts = DB::table('kho_receipts')->orderBy('receipt_date')->orderBy('receipt_time')->orderBy('id')->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'voucher' => $row->voucher_no,
                'date' => substr((string) $row->receipt_date, 0, 10),
                'time' => $row->receipt_time,
                'itemId' => (int) $row->item_id,
                'qty' => (int) $row->quantity,
                'type' => $row->receipt_type,
                'poId' => $row->purchase_order_id !== null ? (int) $row->purchase_order_id : null,
                'status' => $row->status,
                'invoice' => $row->invoice_no,
                'unitPrice' => $row->unit_price !== null ? (float) $row->unit_price : null,
                'note' => $row->note,
                'receiver' => $row->receiver_name,
            ])->all();
        $issues = DB::table('kho_issues')->orderBy('id')->get()->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'voucher' => $row->voucher_no,
            'date' => $row->issue_date !== null ? substr((string) $row->issue_date, 0, 10) : '',
            'time' => (string) ($row->issue_time ?? ''),
            'userId' => (int) $row->recipient_user_id,
            'itemId' => (int) $row->item_id,
            'qty' => (int) $row->quantity,
            'purpose' => $row->purpose,
            'status' => $row->status,
            'note' => $row->note,
            'giver' => $row->giver_name,
            'createdDate' => substr((string) $row->created_at, 0, 10),
        ])->all();
        $orders = DB::table('kho_purchase_orders')->orderBy('id')->get()->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'poNo' => $row->po_no,
            'date' => substr((string) $row->order_date, 0, 10),
            'itemId' => (int) $row->item_id,
            'qty' => (int) $row->quantity,
            'status' => $row->status,
            'type' => $row->order_type,
            'note' => $row->note,
        ])->all();
        $employees = $this->loadEmployees();
        $issueUserIds = array_values(array_unique(array_column($issues, 'userId')));
        $knownUserIds = array_column($employees, 'id');
        if ($issueUserIds) {
            $knownUserIds = array_values(array_unique([
                ...$knownUserIds,
                ...DB::table('users')->whereIn('id', $issueUserIds)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ]));
        }
        $policies = [];
        foreach (DB::table('kho_policies')->orderBy('target_group')->orderBy('family_code')->get() as $policy) {
            $policies[$policy->target_group][] = $policy->family_code;
        }
        $families = [];
        foreach (DB::table('kho_families')->orderBy('code')->get() as $family) {
            $families[$family->code] = ['code' => $family->code, 'name' => $family->name, 'sizeType' => $family->size_type];
        }

        return [
            'items' => $this->loadItems(),
            'receipts' => $receipts,
            'issues' => $issues,
            'orders' => $orders,
            'employees' => $employees,
            'knownUserIds' => $knownUserIds,
            'departmentGroups' => DB::table('kho_department_groups')->pluck('target_group', 'department_id')
                ->mapWithKeys(fn ($group, $departmentId): array => [(string) $departmentId => $group])->all(),
            'policies' => $policies,
            'families' => $families,
        ];
    }

    /** @return array{0: array, 1: array, 2: string, 3: array} [data, settings, ref, computed] */
    protected function state(): array
    {
        $data = $this->loadData();
        $settings = $this->settings();
        $ref = $this->refDate($settings);

        return [$data, $settings, $ref, KhoInventory::compute($data, $settings, $ref)];
    }

    protected function userNames(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        return DB::table('users')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->whereIn('users.id', array_values(array_unique($userIds)))
            ->get(['users.id', 'users.user_code', 'users.name', 'departments.name as department_name'])
            ->mapWithKeys(fn (object $user): array => [(int) $user->id => [
                'code' => (string) ($user->user_code ?? ''),
                'name' => (string) $user->name,
                'departmentName' => (string) ($user->department_name ?? ''),
            ]])
            ->all();
    }

    protected function itemSummary(?array $item): array
    {
        return [
            'itemCode' => $item['code'] ?? '',
            'itemName' => $item['name'] ?? '',
            'itemGroup' => $item['group'] ?? '',
            'unit' => $item['unit'] ?? '',
            'size' => $item['size'] ?? '',
            'supplier' => $item['supplier'] ?? '',
        ];
    }

    protected function log(Request $request, string $action, string $reference = '', string $detail = ''): void
    {
        DB::table('kho_logs')->insert([
            'action' => mb_substr($action, 0, 80),
            'reference' => mb_substr($reference, 0, 60),
            'detail' => $detail,
            'user_id' => $this->user($request)->id,
            'created_at' => Carbon::now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'),
        ]);
    }

    /** Thông báo cho mọi tài khoản có quyền kho để màn hình tự tải lại. */
    protected function broadcastKho(array $extraUserIds = []): void
    {
        $staff = DB::table('kho_staff')->pluck('user_id')->all();
        $leaders = DB::table('users')
            ->where('active', 1)
            ->where(fn ($query) => $query->where('role', 'admin')->orWhere('org_level', 'ceo'))
            ->pluck('id')
            ->all();
        WorkdeskBroadcast::users([...$staff, ...$leaders, ...$extraUserIds], 'kho');
    }

    /** Khóa tuần tự các thao tác ghi sổ kho (tương tự meeting_booking_lock). */
    protected function lockKho(): void
    {
        DB::table('kho_lock')->where('id', 1)->lockForUpdate()->first();
    }

    protected function nextVoucher(string $table, string $column, string $prefix): string
    {
        $base = $prefix.'-'.str_replace('-', '', $this->today()).'-';
        $max = 0;
        foreach (DB::table($table)->where($column, 'like', $base.'%')->pluck($column) as $value) {
            $max = max($max, (int) substr((string) $value, strlen($base)));
        }

        return $base.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    protected function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    protected function cleanText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return mb_strlen($value) > $max ? null : $value;
    }
}
