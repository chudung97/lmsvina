<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Thêm cấp "Quản lý bộ phận" (org_level = division_manager), nằm giữa Trưởng bộ phận và Trưởng phòng:
 * Tổng giám đốc → Trưởng bộ phận → Quản lý bộ phận → Trưởng phòng → Team lead → Nhân viên.
 *
 * - users.org_level: thêm giá trị enum 'division_manager' (giữ nguyên các giá trị và dữ liệu cũ).
 * - divisions.sub_manager_user_id: người quản lý bộ phận (mỗi bộ phận một người, mỗi người một bộ phận).
 */
return new class extends Migration
{
    private const LEVELS_AFTER = ['ceo', 'division_head', 'division_manager', 'department_head', 'team_lead', 'employee'];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $type = DB::selectOne(
                'SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['users', 'org_level'],
            )?->type;
            if ($type !== null && ! str_contains($type, "'division_manager'")) {
                $values = implode(', ', array_map(fn (string $level) => "'{$level}'", self::LEVELS_AFTER));
                DB::statement("ALTER TABLE `users` MODIFY `org_level` ENUM({$values}) NOT NULL DEFAULT 'employee'");
            }
        }

        if (Schema::hasTable('divisions') && ! Schema::hasColumn('divisions', 'sub_manager_user_id')) {
            Schema::table('divisions', function (Blueprint $table): void {
                $table->integer('sub_manager_user_id')->nullable()->after('manager_user_id')
                    ->unique('divisions_sub_manager_unique');
                $table->foreign('sub_manager_user_id', 'divisions_sub_manager_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Không xóa: cột và giá trị enum đang chứa phân công quản lý thật. Trên máy phát triển,
        // dùng `php artisan migrate:fresh` để làm lại từ đầu.
    }
};
