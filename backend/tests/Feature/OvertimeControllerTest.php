<?php

namespace Tests\Feature;

use App\Http\Controllers\OvertimeController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OvertimeControllerTest extends TestCase
{
    private object $manager;
    private object $employee;
    private object $coworker;
    private object $outsider;
    private object $approver;
    private object $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('user_code')->nullable();
            $table->string('role')->default('member');
            $table->string('org_level')->default('employee');
            $table->string('department_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->string('division_id')->nullable();
        });
        Schema::create('divisions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('manager_user_id')->nullable();
        });
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('permission');
        });
        Schema::create('overtime_entries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->date('work_date');
            $table->string('shift_key', 20);
            $table->decimal('hours', 4, 1);
            $table->string('note', 500)->default('');
            $table->string('status', 20)->default('pending');
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'work_date', 'shift_key']);
        });
        Schema::create('overtime_approval_steps', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('entry_id');
            $table->integer('user_id');
            $table->unsignedInteger('sequence');
            $table->string('status', 20)->default('pending');
            $table->string('note', 500)->default('');
            $table->dateTime('decided_at')->nullable();
        });
        Schema::create('overtime_settings', function (Blueprint $table): void {
            $table->string('setting_key', 50)->primary();
            $table->string('setting_value', 255)->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('departments')->insert([
            ['id' => 'dep-a', 'name' => 'Assembly', 'manager_user_id' => 1],
            ['id' => 'dep-b', 'name' => 'Packing', 'manager_user_id' => null],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Manager', 'user_code' => 'M01', 'org_level' => 'department_head', 'department_id' => 'dep-a'],
            ['id' => 2, 'name' => 'Employee', 'user_code' => 'E02', 'org_level' => 'employee', 'department_id' => 'dep-a'],
            ['id' => 3, 'name' => 'Coworker', 'user_code' => 'E03', 'org_level' => 'employee', 'department_id' => 'dep-a'],
            ['id' => 4, 'name' => 'Outsider', 'user_code' => 'E04', 'org_level' => 'employee', 'department_id' => 'dep-b'],
            ['id' => 5, 'name' => 'Approver', 'user_code' => 'A05', 'org_level' => 'employee', 'department_id' => 'dep-b'],
            ['id' => 6, 'name' => 'HR', 'user_code' => 'H06', 'org_level' => 'employee', 'department_id' => null],
        ]);
        DB::table('user_permissions')->insert(['user_id' => 6, 'permission' => 'overtime_hr']);

        $load = fn (int $id): object => DB::table('users')->where('id', $id)->first();
        $this->manager = $load(1);
        $this->employee = $load(2);
        $this->coworker = $load(3);
        $this->outsider = $load(4);
        $this->approver = $load(5);
        $this->hr = $load(6);
    }

    protected function tearDown(): void
    {
        foreach (['overtime_settings', 'overtime_approval_steps', 'overtime_entries', 'user_permissions', 'divisions', 'departments', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_employee_creates_entry_with_approval_line_and_sees_only_own_month(): void
    {
        $created = $this->create($this->employee, '2026-11-05', [5, 1]);
        $this->assertSame(201, $created->getStatusCode());
        $entry = $created->getData(true)['entry'];
        $this->assertSame('pending', $entry['status']);
        $this->assertSame(['5', '1'], array_column($entry['approvalSteps'], 'userId'));
        $this->assertSame('5', $entry['currentApproverId']);

        $this->create($this->coworker, '2026-11-05', [1]);
        $mine = $this->invoke('mine', $this->employee, 'GET', ['month' => '2026-11'])->getData(true);
        $this->assertCount(1, $mine['entries']);
        $this->assertSame(2.0, (float) $mine['totalHours']);

        $team = $this->invoke('team', $this->employee, 'GET', ['month' => '2026-11']);
        $this->assertSame(403, $team->getStatusCode());
    }

    public function test_validation_rejects_bad_shift_missing_line_and_duplicate(): void
    {
        $this->assertSame(400, $this->invoke('store', $this->employee, 'POST', [
            'shift' => 'hc', 'date' => '2026-11-05', 'hours' => 2, 'approverIds' => ['5'],
        ])->getStatusCode());
        $this->assertSame(400, $this->invoke('store', $this->employee, 'POST', [
            'shift' => 'day', 'date' => '2026-11-05', 'hours' => 2, 'approverIds' => [],
        ])->getStatusCode());
        $this->assertSame(400, $this->create($this->employee, '2026-11-05', [2])->getStatusCode());
        $this->assertSame(201, $this->create($this->employee, '2026-11-05', [5])->getStatusCode());
        $this->assertSame(409, $this->create($this->employee, '2026-11-05', [5])->getStatusCode());
    }

    public function test_manager_sees_only_own_department_and_can_edit_approved_entries(): void
    {
        $employeeEntry = $this->create($this->employee, '2026-11-05', [5])->getData(true)['entry'];
        $this->create($this->outsider, '2026-11-05', [5]);

        $team = $this->invoke('team', $this->manager, 'GET', ['month' => '2026-11'])->getData(true);
        $this->assertSame(['Employee'], array_column($team['entries'], 'userName'));
        $this->assertTrue($team['entries'][0]['canEdit']);
        $this->assertSame(['dep-a'], array_column($team['departments'], 'id'));
        $this->assertSame(403, $this->invoke('team', $this->manager, 'GET', ['month' => '2026-11', 'departmentId' => 'dep-b'])->getStatusCode());

        $this->assertSame(200, $this->decision($this->approver, $employeeEntry['id'], 'approve')->getStatusCode());

        $ownerEdit = $this->invoke('update', $this->employee, 'PATCH', [
            'shift' => 'day', 'date' => '2026-11-05', 'hours' => 3, 'approverIds' => ['5'],
        ], $employeeEntry['id']);
        $this->assertSame(409, $ownerEdit->getStatusCode());

        $managerEdit = $this->invoke('update', $this->manager, 'PATCH', [
            'shift' => 'night', 'date' => '2026-11-06', 'hours' => 3, 'note' => 'Sửa bởi quản lý',
        ], $employeeEntry['id']);
        $this->assertSame(200, $managerEdit->getStatusCode());
        $this->assertSame('approved', $managerEdit->getData(true)['entry']['status']);
        $this->assertSame(3.0, (float) $managerEdit->getData(true)['entry']['hours']);

        $outsiderEntryId = (string) DB::table('overtime_entries')->where('user_id', 4)->value('id');
        $this->assertSame(403, $this->invoke('destroy', $this->manager, 'DELETE', [], $outsiderEntryId)->getStatusCode());
        $this->assertSame(200, $this->invoke('destroy', $this->manager, 'DELETE', [], $employeeEntry['id'])->getStatusCode());
    }

    public function test_approval_line_is_sequential_and_reject_requires_reason(): void
    {
        $entry = $this->create($this->employee, '2026-11-05', [5, 1])->getData(true)['entry'];

        $this->assertSame(403, $this->decision($this->manager, $entry['id'], 'approve')->getStatusCode());
        $pending = $this->invoke('approvals', $this->approver, 'GET', ['mode' => 'pending'])->getData(true);
        $this->assertCount(1, $pending['entries']);
        $this->assertCount(0, $this->invoke('approvals', $this->manager, 'GET', ['mode' => 'pending'])->getData(true)['entries']);

        $first = $this->decision($this->approver, $entry['id'], 'approve')->getData(true)['entry'];
        $this->assertSame('pending', $first['status']);
        $this->assertSame('1', $first['currentApproverId']);

        $this->assertSame(400, $this->decision($this->manager, $entry['id'], 'reject')->getStatusCode());
        $rejected = $this->decision($this->manager, $entry['id'], 'reject', 'Sai số giờ')->getData(true)['entry'];
        $this->assertSame('rejected', $rejected['status']);

        $resubmitted = $this->invoke('update', $this->employee, 'PATCH', [
            'shift' => 'day', 'date' => '2026-11-05', 'hours' => 1.5, 'approverIds' => ['1'],
        ], $entry['id'])->getData(true)['entry'];
        $this->assertSame('pending', $resubmitted['status']);
        $this->assertSame(['1'], array_column($resubmitted['approvalSteps'], 'userId'));

        $bulk = $this->invoke('bulkDecision', $this->manager, 'POST', ['ids' => [$entry['id']], 'decision' => 'approve'])->getData(true);
        $this->assertSame(1, $bulk['updated']);
        $this->assertSame('approved', DB::table('overtime_entries')->where('id', $entry['id'])->value('status'));
    }

    public function test_lock_date_blocks_changes_and_only_hr_can_set_it(): void
    {
        $entry = $this->create($this->employee, '2026-11-05', [5])->getData(true)['entry'];

        $this->assertSame(403, $this->invoke('updateLock', $this->manager, 'PUT', ['lockedUntil' => '2026-11-10'])->getStatusCode());
        $this->assertSame(200, $this->invoke('updateLock', $this->hr, 'PUT', ['lockedUntil' => '2026-11-10'])->getStatusCode());

        $this->assertSame(423, $this->create($this->employee, '2026-11-10', [5])->getStatusCode());
        $this->assertSame(201, $this->create($this->employee, '2026-11-11', [5])->getStatusCode());
        $this->assertSame(423, $this->invoke('update', $this->manager, 'PATCH', [
            'shift' => 'day', 'date' => '2026-11-12', 'hours' => 1,
        ], $entry['id'])->getStatusCode());
        $this->assertSame(423, $this->invoke('destroy', $this->employee, 'DELETE', [], $entry['id'])->getStatusCode());

        $mine = $this->invoke('mine', $this->employee, 'GET', ['month' => '2026-11'])->getData(true);
        $locked = collect($mine['entries'])->firstWhere('id', $entry['id']);
        $this->assertTrue($locked['locked']);
        $this->assertFalse($locked['canEdit']);

        $this->assertSame(200, $this->invoke('updateLock', $this->hr, 'PUT', ['lockedUntil' => null])->getStatusCode());
        $this->assertSame(200, $this->invoke('destroy', $this->employee, 'DELETE', [], $entry['id'])->getStatusCode());
    }

    private function create(object $user, string $date, array $approverIds): JsonResponse
    {
        return $this->invoke('store', $user, 'POST', [
            'shift' => 'day',
            'date' => $date,
            'hours' => 2,
            'note' => 'Test',
            'approverIds' => array_map('strval', $approverIds),
        ]);
    }

    private function decision(object $user, string $id, string $decision, string $note = ''): JsonResponse
    {
        return $this->invoke('decision', $user, 'POST', ['decision' => $decision, 'note' => $note], $id);
    }

    private function invoke(string $action, object $user, string $method, array $input, ?string $id = null): JsonResponse
    {
        $request = Request::create('/api/overtime', $method, $input);
        $request->attributes->set('workdesk_user', $user);
        $controller = new OvertimeController;

        return $id === null ? $controller->{$action}($request) : $controller->{$action}($request, $id);
    }
}
