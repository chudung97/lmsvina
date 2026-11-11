<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phiếu ra/vào cổng. Line duyệt nhiều bước nằm ở bảng gate_permit_approval_steps
 * (2026_11_02_000001); approver_id ở đây là người duyệt của quy trình một bước ban đầu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gate_permits')) {
            return;
        }

        Schema::create('gate_permits', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->increments('id');
            $table->integer('requester_id');
            $table->integer('approver_id');
            $table->integer('guard_id');
            $table->enum('direction', ['out', 'in']);
            $table->char('permit_date', 10);
            $table->char('permit_time', 5);
            $table->char('return_time', 5)->nullable();
            $table->string('purpose', 500);
            $table->string('destination', 160);
            $table->string('vehicle_info', 160)->default('');
            $table->enum('status', ['pending_approval', 'pending_guard', 'approved', 'rejected'])
                ->default('pending_approval');
            $table->string('approver_note', 500)->default('');
            $table->string('guard_note', 500)->default('');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('guard_confirmed_at')->nullable();

            $table->index(['requester_id', 'created_at'], 'gate_permits_requester');
            $table->index(['approver_id', 'status', 'permit_date'], 'gate_permits_approver');
            $table->index(['guard_id', 'status', 'permit_date'], 'gate_permits_guard');
            $table->foreign('approver_id', 'gate_permits_approver_fk')->references('id')->on('users');
            $table->foreign('guard_id', 'gate_permits_guard_fk')->references('id')->on('users');
            $table->foreign('requester_id', 'gate_permits_requester_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        // Không xóa: lịch sử phiếu ra/vào cổng là dữ liệu cần lưu vết. Trên máy phát triển,
        // dùng `php artisan migrate:fresh` để làm lại từ đầu.
    }
};
