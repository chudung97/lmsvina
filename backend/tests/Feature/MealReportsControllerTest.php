<?php

namespace Tests\Feature;

use App\Http\Controllers\MealReportsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MealReportsControllerTest extends TestCase
{
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
        Schema::create('meal_report_cutoffs', function (Blueprint $table): void {
            $table->string('shift_key')->primary();
            $table->time('cutoff_time');
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('meal_department_reporters', function (Blueprint $table): void {
            $table->string('department_id');
            $table->string('shift_key');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_by');
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
            $table->unique(['report_date', 'shift_key', 'department_id', 'workshop']);
        });
        Schema::create('meal_korean_reports', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->string('shift_key');
            $table->unsignedTinyInteger('workshop');
            $table->unsignedInteger('korean_qty')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamps();
            $table->unique(['report_date', 'shift_key', 'workshop']);
        });

        DB::table('departments')->insert([
            ['id' => 'dep-a', 'name' => 'Assembly', 'manager_user_id' => 1],
            ['id' => 'dep-b', 'name' => 'Packing', 'manager_user_id' => null],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Department manager', 'role' => 'member', 'org_level' => 'department_head', 'department_id' => 'dep-a', 'active' => true],
            ['id' => 2, 'name' => 'Shift reporter', 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dep-a', 'active' => true],
            ['id' => 3, 'name' => 'HR', 'role' => 'member', 'org_level' => 'employee', 'department_id' => null, 'active' => true],
            ['id' => 4, 'name' => 'Employee', 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dep-a', 'active' => true],
            ['id' => 5, 'name' => 'Other department employee', 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dep-b', 'active' => true],
            ['id' => 99, 'name' => 'System admin', 'role' => 'admin', 'org_level' => 'employee', 'department_id' => null, 'active' => true],
        ]);
        DB::table('user_permissions')->insert([
            ['user_id' => 3, 'permission' => 'meal_hr'],
        ]);
        foreach (['morning', 'noon', 'afternoon', 'night'] as $shift) {
            DB::table('meal_report_cutoffs')->insert([
                'shift_key' => $shift,
                'cutoff_time' => '23:59:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ([
            'meal_reports',
            'meal_korean_reports',
            'meal_department_reporters',
            'meal_report_cutoffs',
            'user_permissions',
            'divisions',
            'departments',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_department_manager_and_hr_can_only_submit_their_meal_types(): void
    {
        $manager = (object) ['id' => 1, 'role' => 'member', 'org_level' => 'department_head', 'department_id' => 'dep-a'];
        $hr = (object) ['id' => 3, 'role' => 'member', 'org_level' => 'employee', 'department_id' => null];
        $employee = (object) ['id' => 4, 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dep-a'];

        $vietnamese = $this->request($manager, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
            'departmentId' => 'dep-a',
            'workshop' => 1,
            'mealType' => 'vietnamese',
            'quantity' => 20,
        ]);
        $korean = $this->request($hr, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
            'workshop' => 1,
            'mealType' => 'korean',
            'quantity' => 5,
        ]);
        $unauthorizedKorean = $this->request($manager, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
            'workshop' => 1,
            'mealType' => 'korean',
            'quantity' => 5,
        ]);
        $unauthorizedVietnamese = $this->request($employee, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
            'departmentId' => 'dep-a',
            'workshop' => 1,
            'mealType' => 'vietnamese',
            'quantity' => 25,
        ]);

        $this->assertSame(200, $vietnamese->getStatusCode());
        $this->assertSame(200, $korean->getStatusCode());
        $this->assertSame(403, $unauthorizedKorean->getStatusCode());
        $this->assertSame(403, $unauthorizedVietnamese->getStatusCode());
        $row = DB::table('meal_reports')->first();
        $this->assertSame(20, $row->vietnamese_qty);
        $this->assertNull($row->korean_qty);
        $this->assertSame(5, DB::table('meal_korean_reports')->value('korean_qty'));
    }

    public function test_employee_from_another_department_cannot_submit_meal_counts(): void
    {
        $otherDepartmentEmployee = (object) [
            'id' => 5,
            'role' => 'member',
            'org_level' => 'employee',
            'department_id' => 'dep-b',
            'active' => true,
        ];
        $baseInput = [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
            'departmentId' => 'dep-a',
            'workshop' => 1,
            'quantity' => 30,
        ];

        $vietnamese = $this->request($otherDepartmentEmployee, 'POST', $baseInput + ['mealType' => 'vietnamese']);
        $korean = $this->request($otherDepartmentEmployee, 'POST', [
            ...array_diff_key($baseInput, ['departmentId' => true]),
            'mealType' => 'korean',
        ]);

        $this->assertSame(403, $vietnamese->getStatusCode());
        $this->assertSame(403, $korean->getStatusCode());
        $this->assertSame(0, DB::table('meal_reports')->count());
        $this->assertSame(0, DB::table('meal_korean_reports')->count());
    }

    public function test_korean_meal_totals_are_stored_and_displayed_by_workshop_not_department(): void
    {
        $hr = (object) ['id' => 3, 'role' => 'member', 'org_level' => 'employee', 'department_id' => null];
        $saved = $this->request($hr, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
            'workshop' => 2,
            'mealType' => 'korean',
            'quantity' => 11,
        ]);
        $result = $this->request($hr, 'GET', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
        ])->getData(true);
        $koreanWithDepartment = $this->request($hr, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
            'departmentId' => 'dep-a',
            'workshop' => 1,
            'mealType' => 'korean',
            'quantity' => 3,
        ]);

        $this->assertSame(200, $saved->getStatusCode());
        $this->assertSame(400, $koreanWithDepartment->getStatusCode());
        $this->assertSame([1, 2], array_column($result['koreanMeals'], 'workshop'));
        $this->assertNull($result['koreanMeals'][0]['koreanQty']);
        $this->assertSame(11, $result['koreanMeals'][1]['koreanQty']);
        $this->assertArrayNotHasKey('koreanQty', $result['departments'][0]['workshops'][0]);
    }

    public function test_department_manager_can_assign_shift_reporter_only_for_their_department(): void
    {
        $manager = (object) ['id' => 1, 'role' => 'member', 'org_level' => 'department_head'];
        $reporter = (object) ['id' => 2, 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dep-a', 'active' => true];

        $assigned = $this->request($manager, 'PUT', ['shift' => 'night', 'userId' => '2'], 'dep-a');
        $wrongDepartment = $this->request($manager, 'PUT', ['shift' => 'night', 'userId' => '2'], 'dep-b');
        $this->assertSame(200, $assigned->getStatusCode());
        $this->assertSame(403, $wrongDepartment->getStatusCode());
        $this->assertSame(1, DB::table('meal_department_reporters')->where('department_id', 'dep-a')->where('shift_key', 'night')->count());

        $allowed = $this->request($reporter, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'night',
            'departmentId' => 'dep-a',
            'workshop' => 2,
            'mealType' => 'vietnamese',
            'quantity' => 14,
        ]);
        $wrongShift = $this->request($reporter, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'noon',
            'departmentId' => 'dep-a',
            'workshop' => 2,
            'mealType' => 'vietnamese',
            'quantity' => 14,
        ]);
        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertSame(403, $wrongShift->getStatusCode());

        DB::table('users')->where('id', 2)->update(['department_id' => 'dep-b']);
        $staleAssignment = $this->request($reporter, 'POST', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'night',
            'departmentId' => 'dep-a',
            'workshop' => 2,
            'mealType' => 'vietnamese',
            'quantity' => 14,
        ]);
        $this->assertSame(403, $staleAssignment->getStatusCode());
    }

    public function test_cutoff_locks_entries_and_opens_company_wide_read_access(): void
    {
        $employee = (object) ['id' => 4, 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dep-a'];
        $yesterday = now('Asia/Ho_Chi_Minh')->subDay()->toDateString();
        $report = $this->request($employee, 'GET', ['date' => $yesterday, 'shift' => 'morning']);
        $response = $report->getData(true);
        $this->assertTrue($response['isClosed']);
        $this->assertCount(2, $response['departments']);

        $locked = $this->request($employee, 'POST', [
            'date' => $yesterday,
            'shift' => 'morning',
            'departmentId' => 'dep-a',
            'workshop' => 1,
            'mealType' => 'vietnamese',
            'quantity' => 1,
        ]);
        $this->assertSame(409, $locked->getStatusCode());
    }

    public function test_company_can_always_see_departments_but_only_managed_department_is_editable(): void
    {
        $manager = (object) ['id' => 1, 'role' => 'member', 'org_level' => 'department_head', 'department_id' => 'dep-a'];
        $result = $this->request($manager, 'GET', [
            'date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shift' => 'morning',
        ])->getData(true);

        $this->assertSame(['Assembly', 'Packing'], array_column($result['departments'], 'name'));
        $this->assertTrue($result['departments'][0]['workshops'][0]['canEditVietnamese']);
        $this->assertFalse($result['departments'][1]['workshops'][0]['canEditVietnamese']);
    }

    public function test_only_system_admin_can_change_daily_shift_cutoffs(): void
    {
        $cutoffs = ['morning' => '07:30', 'noon' => '10:00', 'afternoon' => '14:00', 'night' => '19:00'];
        $manager = (object) ['id' => 1, 'role' => 'member', 'org_level' => 'department_head'];
        $admin = (object) ['id' => 99, 'role' => 'admin', 'org_level' => 'employee'];

        $denied = $this->request($manager, 'PUT', ['cutoffs' => $cutoffs]);
        $saved = $this->request($admin, 'PUT', ['cutoffs' => $cutoffs]);

        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertSame('07:30:00', DB::table('meal_report_cutoffs')->where('shift_key', 'morning')->value('cutoff_time'));
    }

    private function request(object $user, string $method, array $input, ?string $departmentId = null)
    {
        $request = Request::create('/api/meal-reports', $method, $input);
        $request->attributes->set('workdesk_user', $user);
        $controller = new MealReportsController;

        return match ($method) {
            'GET' => $controller->index($request),
            'POST' => $controller->store($request),
            'PUT' => $departmentId === null
                ? $controller->updateCutoffs($request)
                : $controller->updateReporters($request, $departmentId),
        };
    }
}
