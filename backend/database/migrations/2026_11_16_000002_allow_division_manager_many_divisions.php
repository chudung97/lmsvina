<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quản lý bộ phận (org_level = division_manager) là cấp TRÊN Trưởng bộ phận và một người có thể
 * quản lý nhiều bộ phận: Tổng giám đốc → Quản lý bộ phận → Trưởng bộ phận → Trưởng phòng → ...
 *
 * Đổi cột divisions.sub_manager_user_id (migration 2026_11_16_000001, mỗi người một bộ phận) thành
 * divisions.division_manager_user_id không unique. Giữ nguyên các phân công đã có.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('divisions') || Schema::hasColumn('divisions', 'division_manager_user_id')) {
            return;
        }

        if (Schema::hasColumn('divisions', 'sub_manager_user_id')) {
            Schema::table('divisions', function (Blueprint $table): void {
                $table->dropForeign('divisions_sub_manager_fk');
                $table->dropUnique('divisions_sub_manager_unique');
            });
            Schema::table('divisions', function (Blueprint $table): void {
                $table->renameColumn('sub_manager_user_id', 'division_manager_user_id');
            });
        } else {
            Schema::table('divisions', function (Blueprint $table): void {
                $table->integer('division_manager_user_id')->nullable()->after('manager_user_id');
            });
        }

        Schema::table('divisions', function (Blueprint $table): void {
            $table->index('division_manager_user_id', 'divisions_division_manager_index');
            $table->foreign('division_manager_user_id', 'divisions_division_manager_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Không hoàn tác: cột đang chứa phân công quản lý thật.
    }
};
