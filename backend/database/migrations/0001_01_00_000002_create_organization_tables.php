<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cây tổ chức: bộ phận (divisions) → phòng ban (departments) → team, cùng quyền nghiệp vụ
 * của từng tài khoản. Mã đơn vị là UUID (char 36).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('divisions')) {
            Schema::create('divisions', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->char('id', 36)->primary();
                $table->string('name', 120)->unique('divisions_name_unique');
                $table->integer('manager_user_id')->nullable()->unique('divisions_manager_unique');
                $table->dateTime('created_at')->useCurrent();

                $table->foreign('manager_user_id', 'divisions_manager_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->char('id', 36)->primary();
                $table->string('name', 120)->unique('departments_name_unique');
                $table->integer('manager_user_id')->nullable()->unique('departments_manager_unique');
                $table->dateTime('created_at')->useCurrent();
                $table->char('division_id', 36)->nullable();

                $table->foreign('manager_user_id', 'departments_manager_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->char('id', 36)->primary();
                $table->char('department_id', 36);
                $table->string('name', 120);
                $table->integer('manager_user_id')->nullable()->unique('teams_manager_unique');
                $table->dateTime('created_at')->useCurrent();

                $table->unique(['department_id', 'name'], 'teams_department_name_unique');
                $table->foreign('department_id', 'teams_department_fk')
                    ->references('id')->on('departments')->cascadeOnDelete();
                $table->foreign('manager_user_id', 'teams_manager_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('team_members')) {
            Schema::create('team_members', function (Blueprint $table): void {
                $this->tableOptions($table);
                // Mỗi nhân viên thuộc tối đa một team.
                $table->integer('user_id')->primary();
                $table->char('team_id', 36);

                $table->foreign('team_id', 'team_members_team_fk')
                    ->references('id')->on('teams')->cascadeOnDelete();
                $table->foreign('user_id', 'team_members_user_fk')
                    ->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('user_permissions')) {
            Schema::create('user_permissions', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->integer('user_id');
                $table->string('permission', 40);

                $table->primary(['user_id', 'permission']);
                $table->foreign('user_id', 'user_permissions_user_fk')
                    ->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    private function tableOptions(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }

    public function down(): void
    {
        // Không xóa: đây là cơ cấu tổ chức và phân quyền đang dùng. Trên máy phát triển,
        // dùng `php artisan migrate:fresh` để làm lại từ đầu.
    }
};
