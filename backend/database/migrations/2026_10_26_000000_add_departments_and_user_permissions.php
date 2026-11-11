<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table): void {
                $table->char('id', 36)->collation('utf8mb4_0900_ai_ci')->primary();
                $table->string('name', 120)->unique();
                $table->char('manager_user_id', 36)->collation('utf8mb4_0900_ai_ci')->nullable()->unique();
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('manager_user_id')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('users', 'department_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->char('department_id', 36)->collation('utf8mb4_0900_ai_ci')->nullable()->index();
            });
        }

        // Chỉ cấp quyền mặc định khi bảng quyền vừa được tạo lần đầu; nếu bảng đã có
        // (hệ thống đang chạy) thì không được tự cấp quyền cho toàn bộ nhân viên.
        if (Schema::hasTable('user_permissions')) {
            return;
        }

        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->char('user_id', 36)->collation('utf8mb4_0900_ai_ci');
            $table->string('permission', 40);
            $table->primary(['user_id', 'permission']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $existingUsers = DB::table('users')->where('role', '!=', 'admin')->pluck('id');
        foreach ($existingUsers as $userId) {
            DB::table('user_permissions')->insertOrIgnore([
                ['user_id' => $userId, 'permission' => 'permit_approver'],
                ['user_id' => $userId, 'permission' => 'permit_guard'],
                ['user_id' => $userId, 'permission' => 'pdf_signer'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        if (Schema::hasColumn('users', 'department_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('department_id');
            });
        }
        Schema::dropIfExists('departments');
    }
};
