<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phiên đăng nhập Workdesk (cookie). Chỉ lưu mã băm SHA-256 của token, không lưu token gốc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_sessions')) {
            return;
        }

        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->char('token_hash', 64)->primary();
            $table->integer('user_id');
            $table->bigInteger('expires_at');
            $table->dateTime('created_at')->useCurrent();

            $table->index('expires_at', 'auth_sessions_expiry');
            $table->foreign('user_id', 'auth_sessions_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Không xóa: xóa bảng sẽ đăng xuất mọi người. Trên máy phát triển, dùng
        // `php artisan migrate:fresh` để làm lại từ đầu.
    }
};
