<?php

namespace App\Http\Controllers;

use App\Support\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Thiết lập kho: tham số tính toán, dòng sản phẩm, chính sách cấp phát theo nhóm đối
 * tượng, phòng ban → nhóm đối tượng, ngân sách, nhân sự kho và size nhân viên.
 * Phòng ban và nhân viên đọc từ departments/users hiện có; mọi thứ ghi vào bảng kho_.
 */
class KhoSettingsController extends KhoController
{
    public const SHIRT_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL'];

    public const SHOE_SIZES = ['34', '35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'];

    private const NUMBER_SETTINGS = [
        'remindDays' => [1, 365],
        'safetyDays' => [0, 365],
        'reviewDays' => [0, 365],
        'avgMonths' => [1, 24],
        'firstWait' => [0, 365],
        'soonFactor' => [1, 10],
        'staleDays' => [1, 3650],
        'overFactor' => [0.1, 10],
        'remindGap' => [0, 365],
    ];

    private const TEXT_SETTINGS = ['emailTo' => 254, 'receiverName' => 120, 'giverName' => 120, 'stocktakeName' => 120, 'approverName' => 120];

    public function show(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        $year = (int) ($request->query('year') ?: substr($this->today(), 0, 4));
        $budgets = DB::table('kho_budgets')->where('budget_year', $year)->pluck('amount', 'budget_month');
        $groups = DB::table('kho_department_groups')->pluck('target_group', 'department_id');
        $policies = [];
        foreach (DB::table('kho_policies')->orderBy('family_code')->get() as $policy) {
            $policies[$policy->target_group][] = $policy->family_code;
        }

        return response()->json([
            'settings' => $this->settings(),
            'families' => DB::table('kho_families')->orderBy('code')->get(['code', 'name', 'size_type as sizeType']),
            'policies' => (object) $policies,
            'departments' => DB::table('departments')->orderBy('name')->get(['id', 'name'])
                ->map(fn (object $department): array => [
                    'id' => (string) $department->id,
                    'name' => $department->name,
                    'group' => $groups[$department->id] ?? '',
                ]),
            'budget' => [
                'year' => $year,
                'months' => array_map(fn (int $month): float => (float) ($budgets[$month] ?? 0), range(1, 12)),
            ],
            'staff' => DB::table('kho_staff')
                ->join('users', 'users.id', '=', 'kho_staff.user_id')
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.user_code as userCode', 'kho_staff.staff_role as role'])
                ->map(fn (object $row): array => [...(array) $row, 'id' => (string) $row->id]),
            'leaders' => DB::table('users')
                ->where('active', 1)
                ->where(fn ($query) => $query->where('role', 'admin')->orWhere('org_level', 'ceo'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (object $row): array => ['id' => (string) $row->id, 'name' => $row->name]),
            'shirtSizes' => self::SHIRT_SIZES,
            'shoeSizes' => self::SHOE_SIZES,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'manage')) {
            return $denied;
        }
        $input = $request->input('settings');
        $families = $request->input('families');
        $policies = $request->input('policies');
        $departmentGroups = $request->input('departmentGroups');
        $budget = $request->input('budget');
        if (! is_array($input) || ! is_array($families) || ! is_array($policies) || ! is_array($departmentGroups)) {
            return $this->error('Dữ liệu thiết lập không đầy đủ.', 400);
        }

        $settings = [];
        foreach (self::NUMBER_SETTINGS as $key => [$min, $max]) {
            $value = $input[$key] ?? null;
            if (! is_numeric($value) || $value < $min || $value > $max) {
                return $this->error("Tham số {$key} phải từ {$min} đến {$max}.", 400);
            }
            $settings[$key] = $value + 0;
        }
        foreach (self::TEXT_SETTINGS as $key => $max) {
            $value = $this->cleanText($input[$key] ?? '', $max);
            if ($value === null) {
                return $this->error("Tham số {$key} tối đa {$max} ký tự.", 400);
            }
            $settings[$key] = $value;
        }
        foreach (['refDate', 'scheduleStart'] as $key) {
            $value = (string) ($input[$key] ?? '');
            if ($value !== '' && ! $this->validDate($value)) {
                return $this->error("Ngày {$key} không hợp lệ.", 400);
            }
            $settings[$key] = $value;
        }
        if (! in_array($input['valuation'] ?? '', ['avg', 'std'], true)) {
            return $this->error('Phương pháp định giá không hợp lệ.', 400);
        }
        $settings['valuation'] = $input['valuation'];

        $familyRows = [];
        foreach ($families as $family) {
            $code = is_string($family['code'] ?? null) ? trim($family['code']) : '';
            $name = $this->cleanText($family['name'] ?? '', 160);
            $sizeType = $family['sizeType'] ?? 'none';
            if (! preg_match('/^[A-Za-z0-9._\-]{1,40}$/', $code) || ! $name || ! in_array($sizeType, ['shirt', 'pants', 'shoes', 'none'], true)) {
                return $this->error('Mỗi dòng sản phẩm cần mã (chữ, số, . _ -), tên và loại size hợp lệ.', 400);
            }
            if (isset($familyRows[$code])) {
                return $this->error("Mã dòng sản phẩm {$code} bị trùng.", 400);
            }
            $familyRows[$code] = ['code' => $code, 'name' => $name, 'size_type' => $sizeType];
        }

        $policyRows = [];
        foreach ($policies as $group => $codes) {
            $group = trim((string) $group);
            if ($group === '' || mb_strlen($group) > 80 || ! is_array($codes)) {
                return $this->error('Chính sách cấp phát không hợp lệ.', 400);
            }
            foreach (array_unique($codes) as $code) {
                if (! isset($familyRows[$code])) {
                    return $this->error("Chính sách nhóm \"{$group}\" dùng dòng SP {$code} chưa khai báo.", 400);
                }
                $policyRows[] = ['target_group' => $group, 'family_code' => $code];
            }
        }

        $departmentIds = DB::table('departments')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $groupRows = [];
        foreach ($departmentGroups as $row) {
            $departmentId = (string) ($row['departmentId'] ?? '');
            $group = $this->cleanText($row['group'] ?? '', 80);
            if (! in_array($departmentId, $departmentIds, true) || $group === null) {
                return $this->error('Phòng ban hoặc nhóm đối tượng không hợp lệ.', 400);
            }
            if ($group !== '') {
                $groupRows[$departmentId] = ['department_id' => $departmentId, 'target_group' => $group];
            }
        }

        $budgetRows = [];
        if ($budget !== null) {
            $year = filter_var($budget['year'] ?? null, FILTER_VALIDATE_INT);
            $months = $budget['months'] ?? null;
            if (! $year || $year < 2000 || $year > 2100 || ! is_array($months) || count($months) !== 12) {
                return $this->error('Ngân sách cần đủ 12 tháng.', 400);
            }
            foreach (array_values($months) as $index => $amount) {
                if (! is_numeric($amount) || $amount < 0) {
                    return $this->error('Ngân sách tháng phải là số không âm.', 400);
                }
                $budgetRows[] = ['budget_year' => $year, 'budget_month' => $index + 1, 'amount' => (float) $amount];
            }
        }

        DB::transaction(function () use ($settings, $familyRows, $policyRows, $groupRows, $budgetRows): void {
            foreach ($settings as $key => $value) {
                DB::table('kho_settings')->updateOrInsert(
                    ['setting_key' => $key],
                    ['setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
                );
            }
            DB::table('kho_policies')->delete();
            DB::table('kho_families')->whereNotIn('code', array_keys($familyRows) ?: [''])->delete();
            if ($familyRows) {
                DB::table('kho_families')->upsert(array_values($familyRows), ['code'], ['name', 'size_type']);
            }
            if ($policyRows) {
                DB::table('kho_policies')->insert($policyRows);
            }
            DB::table('kho_department_groups')->delete();
            if ($groupRows) {
                DB::table('kho_department_groups')->insert(array_values($groupRows));
            }
            if ($budgetRows) {
                DB::table('kho_budgets')->upsert($budgetRows, ['budget_year', 'budget_month'], ['amount']);
            }
        });
        $this->log($request, 'Lưu thiết lập kho');
        $this->broadcastKho();

        return response()->json(['ok' => true]);
    }

    public function updateStaff(Request $request, string $userId): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'manage')) {
            return $denied;
        }
        $role = $request->input('role');
        if ($role !== null && ! in_array($role, self::STAFF_ROLES, true)) {
            return $this->error('Vai trò kho không hợp lệ.', 400);
        }
        $target = DB::table('users')->where('id', (int) $userId)->first(['id', 'name', 'role', 'org_level', 'active']);
        if (! $target || ! $target->active) {
            return $this->error('Không tìm thấy tài khoản đang hoạt động.', 404);
        }
        if (OrganizationAccess::isOrganizationAdmin($target)) {
            return $this->error('Admin hệ thống và Tổng giám đốc luôn có toàn quyền kho.', 409);
        }
        if ((int) $target->id === (int) $this->user($request)->id) {
            return $this->error('Không thể tự thay đổi quyền kho của chính mình.', 409);
        }
        if ($role === null) {
            DB::table('kho_staff')->where('user_id', $target->id)->delete();
        } else {
            DB::table('kho_staff')->updateOrInsert(
                ['user_id' => $target->id],
                ['staff_role' => $role, 'assigned_by' => $this->user($request)->id],
            );
        }
        $this->log($request, 'Phân quyền kho', (string) $target->id, $target->name.': '.($role ?? 'thu hồi'));
        $this->broadcastKho([$target->id]);

        return response()->json(['ok' => true]);
    }

    public function employees(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [$data, , $ref, $computed] = $this->state();

        return response()->json([
            'ref' => $ref,
            'shirtSizes' => self::SHIRT_SIZES,
            'shoeSizes' => self::SHOE_SIZES,
            'rows' => array_map(fn (array $employee): array => [
                ...$employee,
                'id' => (string) $employee['id'],
                'group' => $data['departmentGroups'][(string) $employee['departmentId']] ?? '',
                'lastIssue' => $computed['lastIssue'][$employee['id']] ?? '',
                'nextDue' => $computed['nextDue'][$employee['id']] ?? '',
            ], $data['employees']),
        ]);
    }

    public function updateSizes(Request $request, string $userId): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $sizes = $this->validSizes($request->all());
        if (is_string($sizes)) {
            return $this->error($sizes, 400);
        }
        if (! DB::table('users')->where('id', (int) $userId)->exists()) {
            return $this->error('Không tìm thấy nhân viên.', 404);
        }
        DB::table('kho_employee_sizes')->updateOrInsert(
            ['user_id' => (int) $userId],
            [...$sizes, 'updated_by' => $this->user($request)->id, 'updated_at' => now()],
        );
        $this->log($request, 'Cập nhật size NV', $userId, implode(' / ', $sizes));
        $this->broadcastKho();

        return response()->json(['ok' => true]);
    }

    public function importSizes(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $rows = $request->input('rows');
        if (! is_array($rows) || ! $rows || count($rows) > 10000) {
            return $this->error('File cần từ 1 đến 10000 dòng dữ liệu.', 400);
        }
        $codes = array_filter(array_map(fn ($row) => is_array($row) ? trim((string) ($row['userCode'] ?? '')) : '', $rows));
        $users = DB::table('users')->whereIn('user_code', $codes)->pluck('id', 'user_code');
        $updated = 0;
        $errors = [];
        DB::transaction(function () use ($rows, $users, $request, &$updated, &$errors): void {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                $code = is_array($row) ? trim((string) ($row['userCode'] ?? '')) : '';
                if (! isset($users[$code])) {
                    $errors[] = "Dòng {$line}: không tìm thấy mã nhân viên \"{$code}\".";

                    continue;
                }
                $sizes = $this->validSizes($row);
                if (is_string($sizes)) {
                    $errors[] = "Dòng {$line} ({$code}): {$sizes}";

                    continue;
                }
                DB::table('kho_employee_sizes')->updateOrInsert(
                    ['user_id' => (int) $users[$code]],
                    [...$sizes, 'updated_by' => $this->user($request)->id, 'updated_at' => now()],
                );
                $updated++;
            }
        });
        $this->log($request, 'Nhập CSV size NV', '', "cập nhật {$updated}, lỗi ".count($errors));
        $this->broadcastKho();

        return response()->json(['updated' => $updated, 'errors' => array_slice($errors, 0, 50)]);
    }

    private function validSizes(array $input): array|string
    {
        $shirt = strtoupper(trim((string) ($input['shirtSize'] ?? '')));
        $pants = strtoupper(trim((string) ($input['pantsSize'] ?? '')));
        $shoes = trim((string) ($input['shoeSize'] ?? ''));
        if ($shirt !== '' && ! in_array($shirt, self::SHIRT_SIZES, true)) {
            return 'Size áo không hợp lệ.';
        }
        if ($pants !== '' && ! in_array($pants, self::SHIRT_SIZES, true)) {
            return 'Size quần không hợp lệ.';
        }
        if ($shoes !== '' && ! in_array($shoes, self::SHOE_SIZES, true)) {
            return 'Size giày không hợp lệ.';
        }

        return ['shirt_size' => $shirt, 'pants_size' => $pants, 'shoe_size' => $shoes];
    }
}
