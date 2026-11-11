<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Mẫu con dấu/chữ ký theo tài khoản và ảnh dấu chụp lại tại thời điểm ký.
 *
 * - user_seal_profiles: thiết kế dấu của từng người (kiểu, chữ, màu, ảnh PNG đã dựng).
 * - document_seal_images: bản sao ảnh dấu lúc người đó đóng dấu tài liệu, để đổi mẫu
 *   dấu sau này không làm thay đổi tài liệu đã ký.
 *
 * Không sửa bảng users; cột seal_text/signature_name/color cũ vẫn được cập nhật để
 * tương thích với tài liệu cũ.
 *
 * Chạy riêng file này từ thư mục backend:
 * php artisan migrate --path=database/migrations/2026_11_05_000001_create_seal_profiles.php --force
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_seal_profiles')) {
            Schema::create('user_seal_profiles', function (Blueprint $table): void {
                $table->integer('user_id')->primary();
                $table->string('style', 20)->default('round');
                $table->string('org_text', 120)->default('');
                $table->string('center_text', 40)->default('');
                $table->string('sub_text', 120)->default('');
                $table->char('color', 7)->default('#c62828');
                $table->string('image_path', 500)->nullable();
                $table->decimal('aspect', 8, 4)->default(1);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('document_seal_images')) {
            Schema::create('document_seal_images', function (Blueprint $table): void {
                $table->unsignedInteger('document_id');
                $table->integer('user_id');
                $table->string('image_path', 500);
                $table->decimal('aspect', 8, 4)->default(1);
                $table->timestamp('created_at')->useCurrent();
                $table->primary(['document_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_seal_images');
        Schema::dropIfExists('user_seal_profiles');
    }
};
