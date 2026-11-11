<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_korean_reports')) {
            Schema::create('meal_korean_reports', function (Blueprint $table): void {
                $table->id();
                $table->date('report_date');
                $table->string('shift_key', 20);
                $table->unsignedTinyInteger('workshop');
                $table->unsignedInteger('korean_qty')->nullable();
                $table->integer('submitted_by')->nullable();
                $table->timestamps();
                $table->unique(
                    ['report_date', 'shift_key', 'workshop'],
                    'meal_korean_reports_date_shift_workshop_unique',
                );
                $table->index(['report_date', 'shift_key'], 'meal_korean_reports_date_shift_index');
                $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('meal_reports')) {
            $legacyKoreanMeals = DB::table('meal_reports')
                ->whereNotNull('korean_qty')
                ->select(
                    'report_date',
                    'shift_key',
                    'workshop',
                    DB::raw('SUM(korean_qty) as korean_qty'),
                    DB::raw('MAX(updated_at) as updated_at'),
                )
                ->groupBy('report_date', 'shift_key', 'workshop')
                ->get();

            foreach ($legacyKoreanMeals as $meal) {
                DB::table('meal_korean_reports')->insertOrIgnore([
                    'report_date' => $meal->report_date,
                    'shift_key' => $meal->shift_key,
                    'workshop' => $meal->workshop,
                    'korean_qty' => $meal->korean_qty,
                    'submitted_by' => null,
                    'created_at' => $meal->updated_at,
                    'updated_at' => $meal->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_korean_reports');
    }
};
