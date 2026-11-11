<?php

namespace Tests\Feature;

use App\Http\Controllers\PermitsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PermitApprovalWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
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
        });
        DB::table('departments')->insert(['id' => 'dep-a', 'name' => 'Assembly']);
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->string('permission');
            $table->primary(['user_id', 'permission']);
        });
        Schema::create('gate_permits', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('requester_id');
            $table->integer('approver_id');
            $table->integer('guard_id');
            $table->string('direction');
            $table->string('permit_date');
            $table->string('permit_time');
            $table->string('return_time')->nullable();
            $table->string('purpose', 500);
            $table->string('destination', 160);
            $table->string('vehicle_info', 160)->default('');
            $table->string('status')->default('pending_approval');
            $table->string('approver_note', 500)->default('');
            $table->string('guard_note', 500)->default('');
            $table->timestamps();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('guard_confirmed_at')->nullable();
        });
        Schema::create('gate_permit_approval_steps', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('permit_id');
            $table->integer('user_id');
            $table->unsignedInteger('sequence');
            $table->boolean('is_hr')->default(false);
            $table->string('status', 20)->default('pending');
            $table->string('note', 500)->default('');
            $table->dateTime('decided_at')->nullable();
            $table->unique(['permit_id', 'sequence']);
        });

        foreach ([
            1 => ['Người lập', []],
            2 => ['Duyệt 1', []],
            3 => ['Duyệt 2', []],
            4 => ['HR', []],
            5 => ['Bảo vệ', []],
            6 => ['Duyệt 3', []],
            7 => ['Duyệt 4', []],
            8 => ['Duyệt 5', []],
            9 => ['Duyệt 6', []],
            10 => ['Duyệt 7', []],
            11 => ['Duyệt 8', []],
            12 => ['Duyệt 9', []],
            13 => ['Duyệt 10', []],
            14 => ['Admin', []],
        ] as $id => [$name, $permissions]) {
            DB::table('users')->insert([
                'id' => $id,
                'name' => $name,
                'role' => $id === 14 ? 'admin' : 'member',
                'department_id' => $id === 2 ? 'dep-a' : null,
            ]);
            foreach ($permissions as $permission) {
                DB::table('user_permissions')->insert([
                    'user_id' => $id,
                    'permission' => $permission,
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('gate_permit_approval_steps');
        Schema::dropIfExists('gate_permits');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_unlimited_approver_line_precedes_required_hr_and_guard_steps(): void
    {
        $controller = new PermitsController;
        $userChoices = $controller->users($this->requestAs(1, []))->getData(true);
        $this->assertContains('2', array_column($userChoices, 'id'));
        $this->assertContains('4', array_column($userChoices, 'id'));
        $this->assertContains('14', array_column($userChoices, 'id'));
        $byId = array_column($userChoices, 'departmentName', 'id');
        $this->assertSame('Assembly', $byId['2']);
        $this->assertNull($byId['4']);

        $payload = [
            'direction' => 'out',
            'date' => '2026-11-02',
            'time' => '09:00',
            'purpose' => 'Gặp khách hàng',
            'destination' => 'Quận 1',
            'approverIds' => ['2', '3', '6', '7', '8', '9', '10', '11', '12', '13'],
            'hrId' => '4',
            'guardId' => '5',
        ];
        $emptyApprovers = $controller->store($this->requestAs(1, [...$payload, 'approverIds' => []]));
        $this->assertSame(400, $emptyApprovers->getStatusCode());

        $missingHr = $controller->store($this->requestAs(1, array_diff_key($payload, ['hrId' => true])));
        $this->assertSame(400, $missingHr->getStatusCode());

        $created = $controller->store($this->requestAs(1, $payload))->getData(true);
        $this->assertCount(11, $created['approvalSteps']);
        $this->assertSame('2', $created['currentApproverId']);
        $this->assertFalse($created['currentApproverIsHr']);

        $permitId = (string) $created['id'];
        $tooEarly = $controller->decision($this->requestAs(3, ['decision' => 'approve']), $permitId);
        $this->assertSame(403, $tooEarly->getStatusCode());

        $line = $payload['approverIds'];
        foreach ($line as $index => $approverId) {
            $approved = $controller->decision(
                $this->requestAs((int) $approverId, ['decision' => 'approve']),
                $permitId,
            )->getData(true);
            $this->assertSame($line[$index + 1] ?? '4', $approved['currentApproverId']);
            if ($index === count($line) - 1) {
                $this->assertTrue($approved['currentApproverIsHr']);
            }
        }

        $hrApproved = $controller->decision($this->requestAs(4, ['decision' => 'approve']), $permitId)->getData(true);
        $this->assertSame('pending_guard', $hrApproved['status']);
        $this->assertNull($hrApproved['currentApproverId']);

        $guardConfirmed = $controller->guard(
            $this->requestAs(5, ['decision' => 'confirm']),
            $permitId,
        )->getData(true);
        $this->assertSame('approved', $guardConfirmed['status']);
        $this->assertNull($guardConfirmed['currentApproverId']);
        $this->assertSame(
            array_fill(0, count($line) + 1, 'approved'),
            array_column($guardConfirmed['approvalSteps'], 'status'),
        );
    }

    private function requestAs(int $userId, array $input): Request
    {
        $request = Request::create('/api/permits', 'POST', $input);
        $request->attributes->set('workdesk_user', (object) [
            'id' => $userId,
            'role' => 'member',
            'org_level' => 'employee',
        ]);

        return $request;
    }
}
