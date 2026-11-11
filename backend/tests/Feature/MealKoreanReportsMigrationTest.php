<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MealKoreanReportsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('meal_reports', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->string('shift_key');
            $table->string('department_id');
            $table->unsignedTinyInteger('workshop');
            $table->unsignedInteger('vietnamese_qty')->nullable();
            $table->unsignedBigInteger('vietnamese_submitted_by')->nullable();
            $table->unsignedInteger('korean_qty')->nullable();
            $table->unsignedBigInteger('korean_submitted_by')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert(['id' => 1]);
        DB::table('meal_reports')->insert([
            [
                'report_date' => '2026-11-03',
                'shift_key' => 'morning',
                'department_id' => 'dep-a',
                'workshop' => 1,
                'vietnamese_qty' => 20,
                'korean_qty' => 3,
                'created_at' => '2026-11-03 01:00:00',
                'updated_at' => '2026-11-03 01:00:00',
            ],
            [
                'report_date' => '2026-11-03',
                'shift_key' => 'morning',
                'department_id' => 'dep-b',
                'workshop' => 1,
                'vietnamese_qty' => 15,
                'korean_qty' => 4,
                'created_at' => '2026-11-03 02:00:00',
                'updated_at' => '2026-11-03 02:00:00',
            ],
            [
                'report_date' => '2026-11-03',
                'shift_key' => 'morning',
                'department_id' => 'dep-a',
                'workshop' => 2,
                'vietnamese_qty' => 12,
                'korean_qty' => null,
                'created_at' => '2026-11-03 03:00:00',
                'updated_at' => '2026-11-03 03:00:00',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('meal_korean_reports');
        Schema::dropIfExists('meal_reports');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_migration_aggregates_existing_department_korean_counts_by_shift_and_workshop(): void
    {
        $migration = require base_path('database/migrations/2026_11_03_000002_create_meal_korean_reports.php');
        $migration->up();

        $reports = DB::table('meal_korean_reports')->orderBy('workshop')->get();

        $this->assertCount(1, $reports);
        $this->assertSame(1, $reports[0]->workshop);
        $this->assertSame(7, $reports[0]->korean_qty);
        $this->assertNull($reports[0]->submitted_by);
        $this->assertSame(47, DB::table('meal_reports')->sum('vietnamese_qty'));
    }
}
