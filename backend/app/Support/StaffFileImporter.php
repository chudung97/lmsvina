<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Nhập thông tin nhân sự từ file "Danh sách công nhân viên" (sheet VN staff 2026) vào bảng users.
 *
 * Quy tắc:
 * - Ghép người theo users.user_code = cột "ID" của file.
 * - CHỈ ghi vào ô đang trống (NULL hoặc chuỗi rỗng). Ô đã có dữ liệu được giữ nguyên, kể cả khi khác
 *   file; các chỗ khác nhau được liệt kê trong báo cáo để HR xem và quyết định.
 * - Không ghi đè full_name/name (chỉ so sánh và báo cáo).
 * - Bỏ qua các cột trống hoàn toàn trong file (lương, phụ cấp) và cột phụ (No, sl, Name lặp lại).
 */
class StaffFileImporter
{
    public const DEFAULT_SHEET = 'VN staff 2026';

    /**
     * [tiêu đề cột trong file, lần xuất hiện (1 = lần đầu), cột trong users, cách chuyển đổi].
     * Tiêu đề so sánh sau khi bỏ khoảng trắng thừa và không phân biệt hoa thường.
     */
    private const FIELDS = [
        // Cột đã có sẵn trong bảng users
        ['Entry date', 1, 'NgayVaoLamViec', 'datetime'],
        ['Birthday', 1, 'NgaySinh', 'datetime'],
        ['ID/ Hộ chiếu', 1, 'CMND', 'text'],
        ['Date issue', 1, 'NgayCap', 'datetime'],
        ['Ngày ký HĐLĐ gần nhất', 1, 'NgayKyHopDong', 'datetime'],
        ['Nation', 1, 'DanToc', 'text'],
        ['Trình độ học vấn', 1, 'TrinhDo', 'text'],
        ['Gender', 1, 'GioiTinh', 'gender'],
        // Cột mới (migration 2026_11_13_000001_add_hr_profile_columns_to_users)
        ['Dept', 1, 'department_name', 'text'],
        ['Postion', 1, 'position', 'text'],
        ['Contract time', 1, 'contract_term', 'text_or_date'],
        ['Place issue', 1, 'id_issue_place', 'text'],
        ['Address', 1, 'address', 'text'],
        ['Place of birth', 1, 'place_of_birth', 'text'],
        ['ID PIT', 1, 'pit_code', 'text'],
        ['ATM', 1, 'bank_account', 'text'],
        ['Ngân Hàng', 1, 'bank_name', 'text'],
        ['Phone number', 1, 'phone', 'text'],
        ['Hồ sơ cần bổ sung', 1, 'missing_documents', 'text'],
        ['SAP ID', 1, 'sap_id', 'text'],
        ['Số phụ lục HĐ', 1, 'contract_appendix_no', 'text'],
        ['Số HĐ', 1, 'contract_no', 'text'],
        ['ID ATM( HD bank)', 1, 'hdbank_atm_id', 'text'],
        ['Thông tin thay đổi', 1, 'info_change_note', 'text'],
        ['ID/ Hộ chiếu cũ 2023', 1, 'old_id_number', 'text'],
        ['Date issue', 2, 'old_id_issue_date', 'date'],
        ['Place issue', 2, 'old_id_issue_place', 'text'],
        ['Địa chỉ cũ trước 1/7/2025', 1, 'old_address', 'text'],
        ['Nơi sinh cũ trước 1/7/2025', 1, 'old_place_of_birth', 'text'],
    ];

    /**
     * @return array{rows:int, matched:int, updatedUsers:int, filled:array<string,int>, kept:array<string,int>,
     *   notInDb:string[], nameDiffs:array<int,array>, conflicts:array<int,array>, invalid:array<int,array>}
     */
    public function run(string $path, string $sheet = self::DEFAULT_SHEET, bool $dryRun = true): array
    {
        $rows = (new XlsxReader($path))->rows($sheet);
        [$headerRow, $columns, $codeColumn, $nameColumn] = $this->locateColumns($rows);
        $userColumns = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('users'));
        foreach (self::FIELDS as [, , $target]) {
            if (! isset($userColumns[$target])) {
                throw new RuntimeException("Bảng users chưa có cột {$target}. Hãy chạy `php artisan migrate` trước.");
            }
        }

        $report = [
            'rows' => 0, 'matched' => 0, 'updatedUsers' => 0, 'filled' => [], 'kept' => [],
            'notInDb' => [], 'nameDiffs' => [], 'conflicts' => [], 'invalid' => [],
        ];
        $targets = array_column(self::FIELDS, 2);
        $updates = [];

        foreach ($rows as $rowNumber => $cells) {
            if ($rowNumber <= $headerRow) {
                continue;
            }
            $code = trim((string) ($cells[$codeColumn] ?? ''));
            if ($code === '') {
                continue;
            }
            $report['rows']++;
            $user = DB::table('users')->where('user_code', $code)->first(['id', 'user_code', 'full_name', ...$targets]);
            if (! $user) {
                $report['notInDb'][] = $code;

                continue;
            }
            $report['matched']++;

            $fileName = $this->clean($cells[$nameColumn] ?? null);
            if ($fileName !== null && $this->normalize($fileName) !== $this->normalize((string) $user->full_name)) {
                $report['nameDiffs'][] = ['user_code' => $code, 'db' => $user->full_name, 'file' => $fileName];
            }

            $changes = [];
            foreach (self::FIELDS as $index => [$label, , $target, $kind]) {
                $raw = $cells[$columns[$index]] ?? null;
                if ($this->clean($raw) === null) {
                    continue;
                }
                $value = $this->convert($raw, $kind);
                if ($value === null) {
                    $report['invalid'][] = ['user_code' => $code, 'column' => $target, 'file' => (string) $raw];

                    continue;
                }
                $current = $user->{$target};
                if ($current === null || trim((string) $current) === '') {
                    $changes[$target] = $value;
                    $report['filled'][$target] = ($report['filled'][$target] ?? 0) + 1;
                } elseif (! $this->sameValue($current, $value, $kind)) {
                    $report['kept'][$target] = ($report['kept'][$target] ?? 0) + 1;
                    $report['conflicts'][] = [
                        'user_code' => $code, 'full_name' => $user->full_name, 'column' => $target,
                        'db' => (string) $current, 'file' => (string) $value,
                    ];
                }
            }
            if ($changes) {
                $updates[$user->id] = $changes;
            }
        }

        $report['updatedUsers'] = count($updates);
        if (! $dryRun && $updates) {
            DB::transaction(function () use ($updates): void {
                foreach ($updates as $userId => $changes) {
                    // Điều kiện "cột đang trống" lặp lại ngay trong câu UPDATE để không bao giờ ghi đè dữ liệu có sẵn.
                    foreach ($changes as $column => $value) {
                        DB::table('users')
                            ->where('id', $userId)
                            ->where(fn ($query) => $query->whereNull($column)->orWhere($column, ''))
                            ->update([$column => $value]);
                    }
                }
            });
        }

        return $report;
    }

    /** @return array{0:int,1:array<int,int>,2:int,3:int} dòng tiêu đề, cột theo FIELDS, cột mã, cột tên */
    private function locateColumns(array $rows): array
    {
        foreach ($rows as $rowNumber => $cells) {
            $labels = [];
            foreach ($cells as $column => $value) {
                $labels[$column] = $this->normalize((string) $value);
            }
            $codeColumn = array_search('id', $labels, true);
            $nameColumn = array_search('name', $labels, true);
            if ($codeColumn === false || $nameColumn === false) {
                continue;
            }

            $columns = [];
            foreach (self::FIELDS as $index => [$label, $occurrence]) {
                $matches = array_keys($labels, $this->normalize($label), true);
                sort($matches);
                if (! isset($matches[$occurrence - 1])) {
                    throw new RuntimeException("File thiếu cột \"{$label}\" (lần {$occurrence}).");
                }
                $columns[$index] = $matches[$occurrence - 1];
            }

            return [$rowNumber, $columns, $codeColumn, $nameColumn];
        }

        throw new RuntimeException('Không tìm thấy dòng tiêu đề có cột "ID" và "Name".');
    }

    private function convert(?string $raw, string $kind): ?string
    {
        $value = $this->clean($raw);

        return match ($kind) {
            'datetime' => ($date = $this->date($value)) ? "{$date} 00:00:00" : null,
            'date' => $this->date($value),
            // Bảng cũ: 1 = Nam, 0 = Nữ (dữ liệu có sẵn chỉ có GioiTinh=0 ở người được ghi "Female").
            'gender' => match (mb_strtolower($value)) {
                'male', 'nam' => '1',
                'female', 'nữ', 'nu' => '0',
                default => null,
            },
            // Ô ngày Excel lưu dạng số serial (vd 46332) => dd/mm/yyyy như các ô dạng chữ.
            'text_or_date' => preg_match('/^\d{5}$/', $value)
                ? (new DateTimeImmutable('1899-12-30'))->modify("+{$value} days")->format('d/m/Y')
                : $value,
            default => $value,
        };
    }

    /** Ngày dạng d/m/Y (hoặc số serial của Excel) => Y-m-d; sai thì null. */
    private function date(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $match)) {
            [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }
        if (preg_match('/^\d{4,5}$/', $value)) {
            return (new DateTimeImmutable('1899-12-30'))->modify("+{$value} days")->format('Y-m-d');
        }

        return null;
    }

    private function sameValue(mixed $current, string $value, string $kind): bool
    {
        if ($kind === 'datetime' || $kind === 'date') {
            return substr((string) $current, 0, 10) === substr($value, 0, 10);
        }

        return $this->normalize((string) $current) === $this->normalize($value);
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    private function normalize(string $value): string
    {
        $value = class_exists(\Normalizer::class) ? (\Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value) : $value;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }
}
