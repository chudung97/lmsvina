<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch họp: phòng họp, cuộc họp (một ngày, qua đêm, lặp hàng tuần), người tham gia và
 * bảng khóa một dòng dùng để tuần tự hóa việc đặt phòng.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meeting_rooms')) {
            Schema::create('meeting_rooms', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->increments('id');
                $table->string('name', 80)->unique('meeting_rooms_name_unique');
                $table->boolean('active')->default(true);
                $table->dateTime('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('meetings')) {
            Schema::create('meetings', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->increments('id');
                $table->string('title', 120);
                // Ngày/giờ lưu dạng chuỗi YYYY-MM-DD và HH:mm theo giờ Việt Nam.
                $table->char('meeting_date', 10);
                $table->char('end_date', 10);
                $table->char('start_time', 5);
                $table->char('end_time', 5);
                $table->unsignedInteger('room_id')->nullable();
                $table->integer('created_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->enum('recurrence_type', ['single', 'overnight', 'weekly'])->default('single');
                $table->char('recurrence_group_id', 36)->nullable();
                $table->text('recurrence_days')->nullable();
                $table->char('recurrence_end_date', 10)->nullable();

                $table->index(['room_id', 'meeting_date', 'start_time', 'end_date', 'end_time'], 'meetings_room_time');
                $table->foreign('created_by', 'meetings_creator_fk')->references('id')->on('users');
                $table->foreign('room_id', 'meetings_room_fk')->references('id')->on('meeting_rooms');
            });
        }

        if (! Schema::hasTable('meeting_participants')) {
            Schema::create('meeting_participants', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->unsignedInteger('meeting_id');
                $table->integer('user_id');

                $table->primary(['meeting_id', 'user_id']);
                $table->index(['user_id', 'meeting_id'], 'meeting_participants_user');
                $table->foreign('meeting_id', 'meeting_participants_meeting_fk')
                    ->references('id')->on('meetings')->cascadeOnDelete();
                $table->foreign('user_id', 'meeting_participants_user_fk')->references('id')->on('users');
            });
        }

        if (! Schema::hasTable('meeting_booking_lock')) {
            Schema::create('meeting_booking_lock', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
                $table->unsignedTinyInteger('id')->primary();
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
        // Không xóa: lịch họp đang dùng. Trên máy phát triển, dùng `php artisan migrate:fresh`
        // để làm lại từ đầu.
    }
};
