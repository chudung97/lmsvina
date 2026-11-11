<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chấm công từ máy ZKTeco.
 * - cham_cong: từng lượt quẹt; user_code là MÃ CHẤM CÔNG (users.timekeeping_code), không phải
 *   mã nhân viên. Dữ liệu do scripts/zkteco-attendance-collector.cjs ghi vào.
 * - bang_cham_cong_ngay: tổng hợp giờ vào/ra theo ngày của hệ thống LMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cham_cong')) {
            Schema::create('cham_cong', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->bigInteger('id', true);
                $table->string('user_code', 20);
                $table->dateTime('check_time');
                $table->integer('verify_mode')->nullable()->default(0);
                $table->integer('in_out_mode')->nullable()->default(0);
                $table->integer('work_code')->nullable()->default(0);
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->string('device_ip', 45)->nullable();
                $table->unsignedSmallInteger('device_record_sn')->nullable();
                $table->string('source_type', 20)->default('legacy');
                $table->binary('raw_record')->nullable();

                $table->index('check_time', 'idx_check_time');
                $table->index(['device_ip', 'device_record_sn'], 'idx_cham_cong_device_record');
            });
        }

        if (! Schema::hasTable('bang_cham_cong_ngay')) {
            Schema::create('bang_cham_cong_ngay', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->bigInteger('id', true);
                $table->string('user_id', 20);
                $table->date('ngay');
                $table->dateTime('gio_vao')->nullable();
                $table->dateTime('gio_ra')->nullable();
                $table->integer('so_lan_quet')->nullable()->default(0);

                $table->unique(['user_id', 'ngay'], 'unique_user_ngay');
                $table->index('ngay', 'idx_ngay');
            });
        }
    }

    private function tableOptions(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_unicode_ci';
    }

    public function down(): void
    {
        // Không xóa: đây là toàn bộ lịch sử chấm công. Trên máy phát triển, dùng
        // `php artisan migrate:fresh` để làm lại từ đầu.
    }
};
