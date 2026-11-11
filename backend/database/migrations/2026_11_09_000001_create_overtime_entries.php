<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tăng ca: mỗi nhân viên tự khai báo ca, ngày, số giờ và ghi chú.
 * Một nhân viên chỉ có một dòng cho mỗi (ngày, ca).
 *
 * php artisan migrate --path=database/migrations/2026_11_09_000001_create_overtime_entries.php --force
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('overtime_entries')) {
            return;
        }

        Schema::create('overtime_entries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->date('work_date');
            $table->string('shift_key', 20);
            $table->decimal('hours', 4, 1);
            $table->string('note', 500)->default('');
            $table->timestamps();
            $table->unique(['user_id', 'work_date', 'shift_key'], 'overtime_entries_user_date_shift_unique');
            $table->index('work_date', 'overtime_entries_work_date_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_entries');
    }
};
