<?php

namespace Tests\Feature;

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\OrganizationController;
use App\Support\OrganizationAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cấp "Quản lý bộ phận" (division_manager): trên Trưởng bộ phận, một người quản lý được nhiều bộ phận.
 */
class DivisionManagerLevelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('user_code')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->default('member');
            $table->string('org_level')->default('employee');
            $table->string('department_id')->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('divisions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedBigInteger('manager_user_id')->nullable()->unique();
            $table->unsignedBigInteger('division_manager_user_id')->nullable()->index();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->unsignedInteger('division_id')->nullable();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('department_id');
            $table->string('name');
            $table->unsignedBigInteger('manager_user_id')->nullable();
        });
        Schema::create('team_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('team_id');
        });
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('permission');
        });

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'role' => 'admin', 'org_level' => 'employee', 'department_id' => null],
            ['id' => 2, 'name' => 'Quản lý BP', 'role' => 'member', 'org_level' => 'employee', 'department_id' => null],
            ['id' => 3, 'name' => 'Trưởng BP 1', 'role' => 'member', 'org_level' => 'employee', 'department_id' => '1'],
            ['id' => 4, 'name' => 'Nhân viên BP 1', 'role' => 'member', 'org_level' => 'employee', 'department_id' => '1'],
            ['id' => 5, 'name' => 'Nhân viên BP 2', 'role' => 'member', 'org_level' => 'employee', 'department_id' => '2'],
            ['id' => 6, 'name' => 'Nhân viên BP 3', 'role' => 'member', 'org_level' => 'employee', 'department_id' => '3'],
            ['id' => 7, 'name' => 'Nhân viên BP 1 khác', 'role' => 'member', 'org_level' => 'employee', 'department_id' => '1'],
        ]);
        DB::table('divisions')->insert([
            ['id' => '1', 'name' => 'Production 1', 'manager_user_id' => null, 'division_manager_user_id' => null],
            ['id' => '2', 'name' => 'Production 2', 'manager_user_id' => null, 'division_manager_user_id' => null],
            ['id' => '3', 'name' => 'Văn phòng', 'manager_user_id' => null, 'division_manager_user_id' => null],
        ]);
        DB::table('departments')->insert([
            ['id' => '1', 'name' => 'Lắp ráp', 'manager_user_id' => null, 'division_id' => '1'],
            ['id' => '2', 'name' => 'Đóng gói', 'manager_user_id' => null, 'division_id' => '2'],
            ['id' => '3', 'name' => 'Kế toán', 'manager_user_id' => null, 'division_id' => '3'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['user_permissions', 'team_members', 'teams', 'departments', 'divisions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_one_division_manager_manages_several_divisions_above_their_heads(): void
    {
        $first = $this->invoke(OrganizationController::class, 'updateDivision', 1, 'PATCH', [
            'name' => 'Production 1', 'managerUserId' => '3', 'divisionManagerUserId' => '2',
        ], '1');
        $this->assertSame(200, $first->status(), json_encode($first->getData()));
        $this->assertSame('2', $first->getData(true)['divisionManagerUserId']);
        $this->assertSame('Quản lý BP', $first->getData(true)['divisionManagerName']);

        // Cùng một người nhận thêm bộ phận thứ hai.
        $second = $this->invoke(OrganizationController::class, 'updateDivision', 1, 'PATCH', [
            'name' => 'Production 2', 'divisionManagerUserId' => '2',
        ], '2');
        $this->assertSame(200, $second->status(), json_encode($second->getData()));
        $this->assertSame('division_manager', $this->user(2)->org_level);
        $this->assertSame('division_head', $this->user(3)->org_level);

        $manager = $this->user(2);
        $head = $this->user(3);
        $this->assertTrue(OrganizationAccess::rank($manager) > OrganizationAccess::rank($head));
        $this->assertTrue(OrganizationAccess::canManageUser($manager, $head));
        $this->assertFalse(OrganizationAccess::canManageUser($head, $manager));
        $this->assertTrue(OrganizationAccess::canManageUser($manager, $this->user(5)));
        $this->assertFalse(OrganizationAccess::canManageUser($manager, $this->user(6)));
        $this->assertTrue(OrganizationAccess::canEditDivision($manager, '2'));
        $this->assertFalse(OrganizationAccess::canEditDivision($manager, '3'));

        $organization = $this->invoke(OrganizationController::class, 'index', 2, 'GET', [])->getData(true);
        $this->assertEqualsCanonicalizing(['1', '2'], array_column($organization['divisions'], 'id'));
        $this->assertEqualsCanonicalizing(['1', '2'], array_column($organization['departments'], 'id'));

        $users = $this->invoke(AccountsController::class, 'listUsers', 2, 'GET', [])->getData(true);
        $this->assertEqualsCanonicalizing(['2', '3', '4', '5', '7'], array_column($users, 'id'));
        $this->assertEqualsCanonicalizing(['1', '2'], collect($users)->firstWhere('id', '2')['managedDivisionIds']);

        $created = $this->invoke(DepartmentsController::class, 'store', 2, 'POST', ['name' => 'QC', 'divisionId' => '2']);
        $this->assertSame(201, $created->status(), json_encode($created->getData()));
        $this->assertSame('4', $created->getData(true)['id']);
        $this->assertSame('2', $created->getData(true)['divisionId']);
        $outside = $this->invoke(DepartmentsController::class, 'store', 2, 'POST', ['name' => 'HR', 'divisionId' => '3']);
        $this->assertSame(403, $outside->status());
    }

    public function test_division_manager_changes_head_but_only_admin_changes_division_manager(): void
    {
        $this->invoke(OrganizationController::class, 'updateDivision', 1, 'PATCH', [
            'name' => 'Production 1', 'managerUserId' => '3', 'divisionManagerUserId' => '2',
        ], '1');

        $headChange = $this->invoke(OrganizationController::class, 'updateDivision', 2, 'PATCH', [
            'name' => 'Production 1', 'managerUserId' => '7',
        ], '1');
        $this->assertSame(200, $headChange->status(), json_encode($headChange->getData()));
        $this->assertSame('employee', $this->user(3)->org_level);
        $this->assertSame('division_head', $this->user(7)->org_level);

        $byManager = $this->invoke(OrganizationController::class, 'updateDivision', 2, 'PATCH', [
            'name' => 'Production 1', 'divisionManagerUserId' => '4',
        ], '1');
        $this->assertSame(403, $byManager->status());
        $byHead = $this->invoke(OrganizationController::class, 'updateDivision', 7, 'PATCH', [
            'name' => 'Production 1', 'managerUserId' => '4',
        ], '1');
        $this->assertSame(403, $byHead->status());

        $sameUser = $this->invoke(OrganizationController::class, 'updateDivision', 1, 'PATCH', [
            'name' => 'Production 1', 'managerUserId' => '7', 'divisionManagerUserId' => '7',
        ], '1');
        $this->assertSame(400, $sameUser->status());
    }

    public function test_access_editor_assigns_several_divisions_and_removal_demotes_only_when_idle(): void
    {
        $assign = fn (array $divisionIds) => $this->invoke(AccountsController::class, 'updateUserAccess', 1, 'PATCH', [
            'orgLevel' => 'division_manager', 'divisionIds' => $divisionIds, 'departmentId' => null, 'teamId' => null, 'permissions' => [],
        ], '2');

        $response = $assign(['1', '2']);
        $this->assertSame(200, $response->status(), json_encode($response->getData()));
        $this->assertSame(['1', '2'], DB::table('divisions')->where('division_manager_user_id', 2)->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all());

        $this->assertSame(200, $assign(['2'])->status());
        $this->assertSame(['2'], DB::table('divisions')->where('division_manager_user_id', 2)->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $this->assertSame(400, $assign([])->status());

        DB::table('divisions')->where('id', '3')->update(['division_manager_user_id' => 6]);
        $this->assertSame(409, $assign(['2', '3'])->status());

        // Gỡ khỏi một bộ phận: vẫn còn bộ phận khác thì giữ cấp; hết bộ phận thì về nhân viên.
        $assign(['1', '2']);
        $this->invoke(OrganizationController::class, 'updateDivision', 1, 'PATCH', ['name' => 'Production 1', 'divisionManagerUserId' => null], '1');
        $this->assertSame('division_manager', $this->user(2)->org_level);
        DB::table('departments')->where('id', '2')->update(['division_id' => null]);
        $deleted = $this->invoke(OrganizationController::class, 'deleteDivision', 1, 'DELETE', [], '2');
        $this->assertSame(200, $deleted->status());
        $this->assertSame('employee', $this->user(2)->org_level);
    }

    public function test_division_manager_tree_endpoint_replaces_divisions_and_removes(): void
    {
        $set = fn (int $actorId, string $userId, array $divisionIds) => $this->invoke(
            OrganizationController::class, 'setDivisionManager', $actorId, 'PUT', ['divisionIds' => $divisionIds], $userId,
        );

        $response = $set(1, '2', ['1', '2']);
        $this->assertSame(200, $response->status(), json_encode($response->getData()));
        $this->assertEqualsCanonicalizing(['1', '2'], $response->getData(true)['divisionIds']);
        $this->assertSame('division_manager', $this->user(2)->org_level);

        $this->assertSame(200, $set(1, '2', ['2', '3'])->status());
        $this->assertEqualsCanonicalizing(['2', '3'], DB::table('divisions')->where('division_manager_user_id', 2)->pluck('id')->map(fn ($id): string => (string) $id)->all());

        // Bộ phận đã có Quản lý khác; người đang quản lý phòng ban; người không có quyền.
        $this->assertSame(409, $set(1, '4', ['3'])->status());
        DB::table('departments')->where('id', '1')->update(['manager_user_id' => 4]);
        $this->assertSame(409, $set(1, '4', ['1'])->status());
        $this->assertSame(403, $set(2, '5', ['1'])->status());

        $this->assertSame(200, $set(1, '2', [])->status());
        $this->assertSame('employee', $this->user(2)->org_level);
        $this->assertSame(0, DB::table('divisions')->whereNotNull('division_manager_user_id')->count());
    }

    public function test_department_can_have_several_named_teams(): void
    {
        $create = fn (array $input) => $this->invoke(OrganizationController::class, 'storeTeam', 1, 'POST', $input);

        $first = $create(['departmentId' => '1', 'name' => 'Lắp ráp ca 1', 'memberUserIds' => ['4']]);
        $this->assertSame(201, $first->status(), json_encode($first->getData()));
        $this->assertSame('Lắp ráp ca 1', $first->getData(true)['name']);
        $this->assertSame('1', $first->getData(true)['departmentId']);

        $second = $create(['departmentId' => '1', 'name' => 'Lắp ráp ca 2', 'memberUserIds' => ['7']]);
        $this->assertSame(201, $second->status(), json_encode($second->getData()));
        $this->assertSame(2, DB::table('teams')->where('department_id', 1)->count());

        $this->assertSame(409, $create(['departmentId' => '1', 'name' => 'Lắp ráp ca 1'])->status());
        $this->assertSame(400, $create(['departmentId' => '1', 'name' => '  '])->status());
        // Một người chỉ thuộc một team.
        $this->assertSame(409, $create(['departmentId' => '1', 'name' => 'Ca 3', 'memberUserIds' => ['4']])->status());
        // Cùng tên nhưng khác phòng ban thì được.
        $this->assertSame(201, $create(['departmentId' => '2', 'name' => 'Lắp ráp ca 1'])->status());

        $secondId = $second->getData(true)['id'];
        $renamed = $this->invoke(OrganizationController::class, 'updateTeam', 1, 'PATCH', ['name' => 'Lắp ráp ca đêm'], $secondId);
        $this->assertSame(200, $renamed->status(), json_encode($renamed->getData()));
        $this->assertSame('Lắp ráp ca đêm', DB::table('teams')->where('id', $secondId)->value('name'));
        $this->assertSame(['7'], $renamed->getData(true)['memberUserIds']);
        $duplicate = $this->invoke(OrganizationController::class, 'updateTeam', 1, 'PATCH', ['name' => 'Lắp ráp ca 1'], $secondId);
        $this->assertSame(409, $duplicate->status());
    }

    private function user(int $id): object
    {
        return DB::table('users')->where('id', $id)->first();
    }

    private function invoke(string $controller, string $action, int $actorId, string $method, array $input, ?string $id = null): JsonResponse
    {
        $request = Request::create('/api/test', $method, $input);
        $request->attributes->set('workdesk_user', $this->user($actorId));

        return $id === null ? (new $controller)->{$action}($request) : (new $controller)->{$action}($request, $id);
    }
}
