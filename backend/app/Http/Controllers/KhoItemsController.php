<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Danh mục hàng: mỗi mã hàng là một dòng (khác size/màu/mẫu → khác mã).
 * family_code nhóm các size của cùng một dòng sản phẩm để lập lịch cấp phát.
 */
class KhoItemsController extends KhoController
{
    private const TEXT_FIELDS = [
        'name' => ['name', 160, true],
        'group' => ['item_group', 40, true],
        'unit' => ['unit', 20, true],
        'color' => ['color', 60, false],
        'size' => ['size', 20, false],
        'spec' => ['spec', 160, false],
        'supplier' => ['supplier', 160, false],
        'family' => ['family_code', 40, false],
        'note' => ['note', 500, false],
    ];

    private const NUMBER_FIELDS = [
        'min' => 'min_qty',
        'max' => 'max_qty',
        'lead' => 'lead_days',
        'cycle' => 'cycle_months',
        'perPerson' => 'qty_per_person',
        'moq' => 'moq',
        'opening' => 'opening_qty',
    ];

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        $items = $this->loadItems();
        $familyCounts = array_count_values(array_filter(array_column($items, 'family')));
        $used = array_flip(array_merge(
            DB::table('kho_issues')->distinct()->pluck('item_id')->all(),
            DB::table('kho_receipts')->distinct()->pluck('item_id')->all(),
            DB::table('kho_purchase_orders')->distinct()->pluck('item_id')->all(),
        ));

        return response()->json(array_map(fn (array $item): array => [
            ...$item,
            'hasTransactions' => isset($used[$item['id']]),
            'warning' => match (true) {
                $item['max'] < $item['min'] => 'max_lt_min',
                $item['family'] !== '' && in_array($item['group'], ['Đồng phục', 'Bảo hộ lao động'], true)
                    && $item['size'] === '' && ($familyCounts[$item['family']] ?? 0) > 1 => 'missing_size',
                default => 'ok',
            },
        ], $items));
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'manage')) {
            return $denied;
        }
        $code = is_string($request->input('code')) ? trim($request->input('code')) : '';
        if (! preg_match('/^[A-Za-z0-9._\-]{1,60}$/', $code)) {
            return $this->error('Mã hàng gồm 1–60 ký tự chữ, số, dấu chấm, gạch ngang hoặc gạch dưới.', 400);
        }
        if (DB::table('kho_items')->where('code', $code)->exists()) {
            return $this->error('Mã hàng đã tồn tại.', 409);
        }
        $values = $this->validated($request->all());
        if (is_string($values)) {
            return $this->error($values, 400);
        }
        $id = DB::table('kho_items')->insertGetId([...$values, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
        $this->log($request, 'Thêm mã hàng', $code);
        $this->broadcastKho();

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'manage')) {
            return $denied;
        }
        $item = DB::table('kho_items')->where('id', (int) $id)->first();
        if (! $item) {
            return $this->error('Không tìm thấy mã hàng.', 404);
        }
        $values = $this->validated($request->all());
        if (is_string($values)) {
            return $this->error($values, 400);
        }
        DB::table('kho_items')->where('id', $item->id)->update([...$values, 'updated_at' => now()]);
        $this->log($request, 'Sửa mã hàng', $item->code);
        $this->broadcastKho();

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'manage')) {
            return $denied;
        }
        $item = DB::table('kho_items')->where('id', (int) $id)->first();
        if (! $item) {
            return $this->error('Không tìm thấy mã hàng.', 404);
        }
        foreach (['kho_issues', 'kho_receipts', 'kho_purchase_orders'] as $table) {
            if (DB::table($table)->where('item_id', $item->id)->exists()) {
                return $this->error('Mã đã có giao dịch – không xóa được. Hãy chuyển sang "Ngừng sử dụng".', 409);
            }
        }
        DB::table('kho_items')->where('id', $item->id)->delete();
        $this->log($request, 'Xóa mã hàng', $item->code);
        $this->broadcastKho();

        return response()->json(['ok' => true]);
    }

    /** Nhập/cập nhật danh mục từ CSV (client đã đọc file và ánh xạ cột). */
    public function import(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'manage')) {
            return $denied;
        }
        $rows = $request->input('rows');
        if (! is_array($rows) || ! $rows || count($rows) > 5000) {
            return $this->error('File cần từ 1 đến 5000 dòng dữ liệu.', 400);
        }
        $added = 0;
        $updated = 0;
        $errors = [];
        DB::transaction(function () use ($rows, &$added, &$updated, &$errors): void {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                if (! is_array($row)) {
                    $errors[] = "Dòng {$line}: dữ liệu không hợp lệ.";

                    continue;
                }
                $code = is_string($row['code'] ?? null) ? trim($row['code']) : '';
                if (! preg_match('/^[A-Za-z0-9._\-]{1,60}$/', $code)) {
                    $errors[] = "Dòng {$line}: mã hàng không hợp lệ.";

                    continue;
                }
                $existing = DB::table('kho_items')->where('code', $code)->first();
                $base = $existing ? [
                    'name' => $existing->name, 'group' => $existing->item_group, 'unit' => $existing->unit,
                    'color' => $existing->color, 'size' => $existing->size, 'spec' => $existing->spec,
                    'supplier' => $existing->supplier, 'family' => $existing->family_code, 'note' => $existing->note,
                    'price' => $existing->unit_price, 'min' => $existing->min_qty, 'max' => $existing->max_qty,
                    'lead' => $existing->lead_days, 'cycle' => $existing->cycle_months, 'perPerson' => $existing->qty_per_person,
                    'moq' => $existing->moq, 'opening' => $existing->opening_qty, 'active' => (bool) $existing->active,
                ] : ['unit' => 'Cái', 'lead' => 7, 'moq' => 1, 'active' => true];
                $merged = $base;
                foreach ($row as $key => $value) {
                    if ($value !== '' && $value !== null) {
                        $merged[$key] = $value;
                    }
                }
                $values = $this->validated($merged);
                if (is_string($values)) {
                    $errors[] = "Dòng {$line} ({$code}): {$values}";

                    continue;
                }
                if ($existing) {
                    DB::table('kho_items')->where('id', $existing->id)->update([...$values, 'updated_at' => now()]);
                    $updated++;
                } else {
                    DB::table('kho_items')->insert([...$values, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
                    $added++;
                }
            }
        });
        $this->log($request, 'Nhập CSV danh mục', '', "thêm {$added}, cập nhật {$updated}, lỗi ".count($errors));
        $this->broadcastKho();

        return response()->json(['added' => $added, 'updated' => $updated, 'errors' => array_slice($errors, 0, 50)]);
    }

    private function validated(array $input): array|string
    {
        $values = [];
        foreach (self::TEXT_FIELDS as $key => [$column, $max, $required]) {
            $value = $this->cleanText($input[$key] ?? '', $max);
            if ($value === null || ($required && $value === '')) {
                return "Trường \"{$key}\" bắt buộc hoặc vượt quá {$max} ký tự.";
            }
            $values[$column] = $value;
        }
        if (! in_array($values['item_group'], self::ITEM_GROUPS, true)) {
            return 'Nhóm hàng phải là: '.implode(', ', self::ITEM_GROUPS).'.';
        }
        $price = $input['price'] ?? 0;
        if (! is_numeric($price) || $price < 0 || $price > 9999999999) {
            return 'Đơn giá phải là số không âm.';
        }
        $values['unit_price'] = round((float) $price, 2);
        foreach (self::NUMBER_FIELDS as $key => $column) {
            $value = $input[$key] ?? 0;
            if ($value === '' || $value === null) {
                $value = 0;
            }
            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 100000000) {
                return "Trường \"{$key}\" phải là số nguyên không âm.";
            }
            $values[$column] = (int) $value;
        }
        if ($values['moq'] < 1) {
            $values['moq'] = 1;
        }
        if ($values['max_qty'] < $values['min_qty']) {
            return 'Tồn tối đa phải lớn hơn hoặc bằng tồn tối thiểu.';
        }
        $values['active'] = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        return $values;
    }
}
