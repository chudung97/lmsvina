<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gate_permit_approval_steps')) {
            Schema::create('gate_permit_approval_steps', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('permit_id');
                $table->integer('user_id');
                $table->unsignedInteger('sequence');
                $table->boolean('is_hr')->default(false);
                $table->string('status', 20)->default('pending');
                $table->string('note', 500)->default('');
                $table->dateTime('decided_at')->nullable();
                $table->unique(['permit_id', 'sequence'], 'gate_permit_approval_steps_order');
                $table->index(['user_id', 'status'], 'gate_permit_approval_steps_user');
                $table->foreign('permit_id')
                    ->references('id')->on('gate_permits')->cascadeOnDelete();
                $table->foreign('user_id')
                    ->references('id')->on('users');
            });
        }

        DB::table('gate_permits')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('gate_permit_approval_steps')
                    ->whereColumn('gate_permit_approval_steps.permit_id', 'gate_permits.id');
            })
            ->orderBy('id')
            ->chunkById(500, function ($permits): void {
                foreach ($permits as $permit) {
                    DB::table('gate_permit_approval_steps')->insert([
                        'permit_id' => $permit->id,
                        'user_id' => $permit->approver_id,
                        'sequence' => 1,
                        'is_hr' => false,
                        'status' => $permit->status === 'pending_approval'
                            ? 'pending'
                            : ($permit->status === 'rejected' && ! $permit->guard_confirmed_at ? 'rejected' : 'approved'),
                        'note' => $permit->approver_note,
                        'decided_at' => $permit->approved_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_permit_approval_steps');
    }
};
