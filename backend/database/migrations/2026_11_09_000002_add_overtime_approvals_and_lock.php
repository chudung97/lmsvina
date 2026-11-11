<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tăng ca: line duyệt, trạng thái duyệt và ngày chốt công.
 *
 * - overtime_entries.status: pending | approved | rejected.
 * - overtime_approval_steps: line người duyệt theo thứ tự cho từng dòng tăng ca.
 * - overtime_settings: cấu hình chung; key "locked_until" là ngày chốt công
 *   (mọi ngày <= ngày này không được thêm/sửa/xóa tăng ca nữa).
 *
 * Chạy sau 2026_11_09_000001_create_overtime_entries.php:
 * php artisan migrate --path=database/migrations/2026_11_09_000002_add_overtime_approvals_and_lock.php --force
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('overtime_entries', 'status')) {
            Schema::table('overtime_entries', function (Blueprint $table): void {
                $table->string('status', 20)->default('pending')->after('note');
                $table->integer('updated_by')->nullable()->after('status');
                $table->index(['user_id', 'status'], 'overtime_entries_user_status_index');
            });
        }

        if (! Schema::hasTable('overtime_approval_steps')) {
            Schema::create('overtime_approval_steps', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('entry_id');
                $table->integer('user_id');
                $table->unsignedInteger('sequence');
                $table->string('status', 20)->default('pending');
                $table->string('note', 500)->default('');
                $table->dateTime('decided_at')->nullable();
                $table->unique(['entry_id', 'sequence'], 'overtime_approval_steps_order');
                $table->index(['user_id', 'status'], 'overtime_approval_steps_user');
                $table->foreign('entry_id')->references('id')->on('overtime_entries')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users');
            });
        }

        if (! Schema::hasTable('overtime_settings')) {
            Schema::create('overtime_settings', function (Blueprint $table): void {
                $table->string('setting_key', 50)->primary();
                $table->string('setting_value', 255)->nullable();
                $table->integer('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_settings');
        Schema::dropIfExists('overtime_approval_steps');
        if (Schema::hasColumn('overtime_entries', 'status')) {
            Schema::table('overtime_entries', function (Blueprint $table): void {
                $table->dropIndex('overtime_entries_user_status_index');
                $table->dropColumn(['status', 'updated_by']);
            });
        }
    }
};
