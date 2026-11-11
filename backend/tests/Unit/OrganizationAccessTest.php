<?php

namespace Tests\Unit;

use App\Support\OrganizationAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('role')->default('member');
            $table->string('org_level')->default('employee');
            $table->string('department_id')->nullable();
        });
        Schema::create('divisions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->integer('manager_user_id')->nullable();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('division_id')->nullable();
            $table->integer('manager_user_id')->nullable();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('department_id');
            $table->integer('manager_user_id')->nullable();
        });
        Schema::create('team_members', function (Blueprint $table): void {
            $table->integer('user_id')->primary();
            $table->string('team_id');
        });

        DB::table('users')->insert([
            ['id' => 1, 'role' => 'member', 'org_level' => 'ceo', 'department_id' => null],
            ['id' => 2, 'role' => 'member', 'org_level' => 'department_head', 'department_id' => 'dept-a'],
            ['id' => 3, 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dept-a'],
            ['id' => 4, 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dept-b'],
            ['id' => 5, 'role' => 'admin', 'org_level' => 'employee', 'department_id' => null],
            ['id' => 6, 'role' => 'member', 'org_level' => 'team_lead', 'department_id' => 'dept-a'],
        ]);
        DB::table('departments')->insert([
            ['id' => 'dept-a', 'division_id' => 'division-a', 'manager_user_id' => 2],
            ['id' => 'dept-b', 'division_id' => 'division-b', 'manager_user_id' => null],
        ]);
        DB::table('teams')->insert([
            ['id' => 'team-a', 'department_id' => 'dept-a', 'manager_user_id' => 6],
            ['id' => 'team-b', 'department_id' => 'dept-a', 'manager_user_id' => null],
        ]);
        DB::table('team_members')->insert([
            ['user_id' => 3, 'team_id' => 'team-a'],
            ['user_id' => 4, 'team_id' => 'team-b'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('divisions');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_department_head_can_manage_only_subordinates_in_the_managed_department(): void
    {
        $head = $this->user(2, 'department_head');
        $sameDepartment = $this->user(3, 'employee', 'dept-a');
        $otherDepartment = $this->user(4, 'employee', 'dept-b');

        $this->assertTrue(OrganizationAccess::canManageUser($head, $sameDepartment));
        $this->assertFalse(OrganizationAccess::canManageUser($head, $otherDepartment));
        $this->assertTrue(OrganizationAccess::canManageTeam($head, 'team-a'));
        $this->assertTrue(OrganizationAccess::canManageTeam($head, 'team-b'));
        $this->assertSame([2, 3, 6], OrganizationAccess::visibleUsersQuery($head)->orderBy('users.id')->pluck('users.id')->all());
    }

    public function test_team_lead_can_manage_only_members_of_the_managed_team(): void
    {
        $lead = $this->user(6, 'team_lead', 'dept-a');
        $teamMember = $this->user(3, 'employee', 'dept-a');
        $otherTeamMember = $this->user(4, 'employee', 'dept-a');

        $this->assertTrue(OrganizationAccess::canManageUser($lead, $teamMember));
        $this->assertFalse(OrganizationAccess::canManageUser($lead, $otherTeamMember));
        $this->assertTrue(OrganizationAccess::canManageTeam($lead, 'team-a'));
        $this->assertFalse(OrganizationAccess::canManageTeam($lead, 'team-b'));
        $this->assertSame([3, 6], OrganizationAccess::visibleUsersQuery($lead)->orderBy('users.id')->pluck('users.id')->all());
    }

    public function test_ceo_can_manage_employees_but_not_system_administrators(): void
    {
        $ceo = $this->user(1, 'ceo');
        $employee = $this->user(4, 'employee', 'dept-b');
        $admin = (object) ['id' => 5, 'role' => 'admin', 'org_level' => 'employee', 'department_id' => null];

        $this->assertTrue(OrganizationAccess::canManageUser($ceo, $employee));
        $this->assertFalse(OrganizationAccess::canManageUser($ceo, $admin));
        $this->assertTrue(OrganizationAccess::canAssignLevel($ceo, 'team_lead'));
        $this->assertFalse(OrganizationAccess::canAssignLevel($ceo, 'ceo'));
    }

    private function user(int $id, string $level, ?string $departmentId = null): object
    {
        return (object) [
            'id' => $id,
            'role' => 'member',
            'org_level' => $level,
            'department_id' => $departmentId,
        ];
    }
}
