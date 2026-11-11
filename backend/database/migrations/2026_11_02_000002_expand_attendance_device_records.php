<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cham_cong')) {
            return;
        }

        $columns = [
            'device_ip' => fn (Blueprint $table) => $table->string('device_ip', 45)->nullable(),
            'device_record_sn' => fn (Blueprint $table) => $table->unsignedSmallInteger('device_record_sn')->nullable(),
            'source_type' => fn (Blueprint $table) => $table->string('source_type', 20)->default('legacy'),
            'raw_record' => fn (Blueprint $table) => $table->binary('raw_record')->nullable(),
        ];

        $missingColumns = array_filter(
            $columns,
            fn ($definition, $name) => ! Schema::hasColumn('cham_cong', $name),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($missingColumns !== []) {
            Schema::table('cham_cong', function (Blueprint $table) use ($missingColumns): void {
                foreach ($missingColumns as $definition) {
                    $definition($table);
                }
            });
        }

        foreach (Schema::getIndexes('cham_cong') as $index) {
            if ($index['unique']
                && $index['columns'] === ['user_code', 'check_time']) {
                Schema::table('cham_cong', fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }

        if (! Schema::hasIndex('cham_cong', ['device_ip', 'device_record_sn'])) {
            Schema::table('cham_cong', function (Blueprint $table): void {
                $table->index(['device_ip', 'device_record_sn'], 'idx_cham_cong_device_record');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cham_cong')) {
            return;
        }

        $duplicates = DB::table('cham_cong')
            ->select('user_code', 'check_time')
            ->groupBy('user_code', 'check_time')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Cannot restore unique_check while attendance records contain duplicate user/time pairs.');
        }

        foreach (Schema::getIndexes('cham_cong') as $index) {
            if ($index['name'] === 'idx_cham_cong_device_record') {
                Schema::table('cham_cong', fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }

        if (! collect(Schema::getIndexes('cham_cong'))->contains(
            fn (array $index): bool => $index['unique'] && $index['columns'] === ['user_code', 'check_time'],
        )) {
            Schema::table('cham_cong', function (Blueprint $table): void {
                $table->unique(['user_code', 'check_time'], 'unique_check');
            });
        }

        foreach (['raw_record', 'source_type', 'device_record_sn', 'device_ip'] as $column) {
            if (Schema::hasColumn('cham_cong', $column)) {
                Schema::table('cham_cong', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
