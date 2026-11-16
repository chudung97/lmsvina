<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Đổi khóa chính UUID (char 36) của divisions, departments, teams sang số tự tăng 1, 2, 3...
 * và cập nhật mọi cột đang lưu các id đó:
 *   divisions.id    ← departments.division_id
 *   departments.id  ← users.department_id, teams.department_id, meal_reports.department_id,
 *                     meal_department_reporters.department_id, kho_department_groups.department_id
 *   teams.id        ← team_members.team_id
 *
 * Số mới cấp theo thứ tự tạo (created_at, rồi tên). Khóa ngoại được gỡ tạm và tạo lại đúng tên,
 * đúng quy tắc ON DELETE như cũ. Chỉ chạy trên MySQL và chỉ khi id vẫn là chuỗi.
 * DDL của MySQL không nằm trong transaction: hãy sao lưu database trước khi chạy.
 */
return new class extends Migration
{
    /** Bảng gốc => các cột tham chiếu [bảng, cột, NOT NULL?]. */
    private const REFERENCES = [
        'divisions' => [
            ['departments', 'division_id', false],
        ],
        'departments' => [
            ['users', 'department_id', false],
            ['teams', 'department_id', true],
            ['meal_reports', 'department_id', true],
            ['meal_department_reporters', 'department_id', true],
            ['kho_department_groups', 'department_id', true],
        ],
        'teams' => [
            ['team_members', 'team_id', true],
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('departments')) {
            return;
        }
        $type = DB::selectOne(
            'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['departments', 'id'],
        )?->type;
        if (! in_array($type, ['char', 'varchar'], true)) {
            return;
        }

        // 1. Gỡ tạm các khóa ngoại trỏ tới hoặc nằm trên các cột sắp đổi kiểu.
        $foreignKeys = $this->foreignKeysOnColumns();
        foreach ($foreignKeys as $key) {
            DB::statement("ALTER TABLE `{$key->table_name}` DROP FOREIGN KEY `{$key->constraint_name}`");
        }

        // 2. Đổi giá trị: id cũ (UUID) => số mới, ở bảng gốc và mọi cột tham chiếu.
        DB::transaction(function (): void {
            foreach (self::REFERENCES as $table => $references) {
                $oldIds = DB::table($table)->orderBy('created_at')->orderBy('name')->orderBy('id')->pluck('id');
                foreach ($oldIds as $index => $oldId) {
                    $newId = (string) ($index + 1);
                    foreach ($references as [$referenceTable, $column]) {
                        if (Schema::hasTable($referenceTable)) {
                            DB::table($referenceTable)->where($column, $oldId)->update([$column => $newId]);
                        }
                    }
                    DB::table($table)->where('id', $oldId)->update(['id' => $newId]);
                }
            }
        });

        // 3. Đổi kiểu cột sang số. Khóa chính/unique/index giữ nguyên.
        foreach (array_keys(self::REFERENCES) as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }
        foreach (self::REFERENCES as $references) {
            foreach ($references as [$referenceTable, $column, $required]) {
                if (Schema::hasTable($referenceTable)) {
                    $null = $required ? 'NOT NULL' : 'NULL DEFAULT NULL';
                    DB::statement("ALTER TABLE `{$referenceTable}` MODIFY `{$column}` INT UNSIGNED {$null}");
                }
            }
        }

        // 4. Tạo lại khóa ngoại như cũ.
        foreach ($foreignKeys as $key) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s',
                $key->table_name, $key->constraint_name, $key->column_name,
                $key->referenced_table_name, $key->referenced_column_name, $key->delete_rule, $key->update_rule,
            ));
        }
    }

    public function down(): void
    {
        // Không hoàn tác: id cũ dạng UUID không còn. Khôi phục từ bản sao lưu nếu cần.
    }

    /** Khóa ngoại liên quan tới divisions/departments/teams.id hoặc các cột tham chiếu. */
    private function foreignKeysOnColumns(): array
    {
        $columns = [];
        foreach (self::REFERENCES as $table => $references) {
            $columns[] = [$table, 'id'];
            foreach ($references as [$referenceTable, $column]) {
                $columns[] = [$referenceTable, $column];
            }
        }
        $keys = DB::select(
            'SELECT k.TABLE_NAME AS table_name, k.CONSTRAINT_NAME AS constraint_name, k.COLUMN_NAME AS column_name,
                    k.REFERENCED_TABLE_NAME AS referenced_table_name, k.REFERENCED_COLUMN_NAME AS referenced_column_name,
                    r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL',
        );

        return array_values(array_filter($keys, function (object $key) use ($columns): bool {
            foreach ($columns as [$table, $column]) {
                if ($key->table_name === $table && $key->column_name === $column
                    || $key->referenced_table_name === $table && $key->referenced_column_name === $column) {
                    return true;
                }
            }

            return false;
        }));
    }
};
