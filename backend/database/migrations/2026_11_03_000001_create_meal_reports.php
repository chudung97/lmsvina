<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_report_cutoffs')) {
            Schema::create('meal_report_cutoffs', function (Blueprint $table): void {
                $table->string('shift_key', 20)->primary();
                $table->time('cutoff_time')->default('23:59:00');
                $table->timestamp('updated_at')->nullable();
            });
        }

        foreach (['morning', 'noon', 'afternoon', 'night'] as $shift) {
            DB::table('meal_report_cutoffs')->insertOrIgnore([
                'shift_key' => $shift,
                'cutoff_time' => '23:59:00',
            ]);
        }

        if (! Schema::hasTable('meal_department_reporters')) {
            Schema::create('meal_department_reporters', function (Blueprint $table): void {
                $table->char('department_id', 36)->collation('utf8mb4_0900_ai_ci');
                $table->string('shift_key', 20);
                $table->integer('user_id');
                $table->integer('assigned_by');
                $table->timestamp('created_at')->useCurrent();
                $table->primary(['department_id', 'shift_key', 'user_id']);
                $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('assigned_by')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('meal_reports')) {
            Schema::create('meal_reports', function (Blueprint $table): void {
                $table->id();
                $table->date('report_date');
                $table->string('shift_key', 20);
                $table->char('department_id', 36)->collation('utf8mb4_0900_ai_ci');
                $table->unsignedTinyInteger('workshop');
                $table->unsignedInteger('vietnamese_qty')->nullable();
                $table->integer('vietnamese_submitted_by')->nullable();
                $table->unsignedInteger('korean_qty')->nullable();
                $table->integer('korean_submitted_by')->nullable();
                $table->timestamps();
                $table->unique(
                    ['report_date', 'shift_key', 'department_id', 'workshop'],
                    'meal_reports_date_shift_department_workshop_unique',
                );
                $table->index(['report_date', 'shift_key'], 'meal_reports_date_shift_index');
                $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
                $table->foreign('vietnamese_submitted_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('korean_submitted_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_reports');
        Schema::dropIfExists('meal_department_reporters');
        Schema::dropIfExists('meal_report_cutoffs');
    }
};
