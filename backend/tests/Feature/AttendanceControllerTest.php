<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('user_code')->nullable();
            $table->string('timekeeping_code')->nullable();
            $table->string('name');
            $table->string('role')->default('member');
            $table->string('org_level')->default('employee');
            $table->integer('department_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('division_id')->nullable();
            $table->integer('manager_user_id')->nullable();
        });
        Schema::create('cham_cong', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('user_code');
            $table->dateTime('check_time');
            $table->unique(['user_code', 'check_time']);
        });

        DB::table('users')->insert([
            ['id' => 1, 'user_code' => 'LOGIN-100', 'timekeeping_code' => 'E100', 'name' => 'Nguyen An', 'role' => 'member', 'org_level' => 'employee'],
            ['id' => 2, 'user_code' => 'LOGIN-200', 'timekeeping_code' => 'E200', 'name' => 'Tran Binh', 'role' => 'member', 'org_level' => 'employee'],
            ['id' => 99, 'user_code' => 'ADMIN', 'timekeeping_code' => null, 'name' => 'Admin', 'role' => 'admin', 'org_level' => 'employee'],
        ]);
        DB::table('cham_cong')->insert([
            ['user_code' => 'E100', 'check_time' => '2025-01-02 08:00:00'],
            ['user_code' => 'E100', 'check_time' => '2025-01-02 17:00:00'],
            ['user_code' => 'E200', 'check_time' => '2025-01-03 08:00:00'],
            ...array_map(
                fn (int $minute): array => [
                    'user_code' => 'E200',
                    'check_time' => sprintf('2025-01-03 08:%02d:00', $minute),
                ],
                range(1, 8),
            ),
        ]);
    }

    public function test_admin_can_search_by_employee_code_and_filter_inclusive_dates(): void
    {
        $result = $this->requestFor((object) ['id' => 99, 'role' => 'admin', 'org_level' => 'employee'], [
            'search' => 'E100',
            'from' => '2025-01-02',
            'to' => '2025-01-02',
        ]);

        $this->assertSame(2, $result['total']);
        $this->assertSame(['LOGIN-100', 'LOGIN-100'], array_column($result['data'], 'userCode'));
        $this->assertSame(['E100', 'E100'], array_column($result['data'], 'timekeepingCode'));
        $this->assertSame(['Nguyen An', 'Nguyen An'], array_column($result['data'], 'userName'));
        $this->assertSame(25, $result['perPage']);

        $byEmployeeCode = $this->requestFor((object) ['id' => 99, 'role' => 'admin', 'org_level' => 'employee'], [
            'search' => 'LOGIN-200',
        ]);
        $this->assertSame(9, $byEmployeeCode['total']);
    }

    public function test_admin_pagination_returns_newest_records_first(): void
    {
        $result = $this->requestFor((object) ['id' => 99, 'role' => 'admin', 'org_level' => 'employee'], [
            'page' => 2,
            'perPage' => 10,
        ]);

        $this->assertSame(11, $result['total']);
        $this->assertSame(2, $result['totalPages']);
        $this->assertSame('E100', $result['data'][0]['timekeepingCode']);
    }

    public function test_employee_only_sees_their_own_attendance_records(): void
    {
        $result = $this->requestFor((object) ['id' => 1, 'role' => 'member', 'org_level' => 'employee'], [
            'search' => 'E200',
        ]);

        $this->assertSame(0, $result['total']);
        $ownRecords = $this->requestFor((object) ['id' => 1, 'role' => 'member', 'org_level' => 'employee']);
        $this->assertSame(2, $ownRecords['total']);
        $this->assertSame(['LOGIN-100', 'LOGIN-100'], array_column($ownRecords['data'], 'userCode'));
    }

    public function test_rejects_invalid_date_ranges_and_page_sizes(): void
    {
        $admin = (object) ['id' => 99, 'role' => 'admin', 'org_level' => 'employee'];
        $rangeError = $this->requestResponse($admin, ['from' => '2025-01-03', 'to' => '2025-01-02']);
        $pageSizeError = $this->requestResponse($admin, ['perPage' => 500]);

        $this->assertSame(400, $rangeError->getStatusCode());
        $this->assertSame(400, $pageSizeError->getStatusCode());
    }

    private function requestFor(object $user, array $query = []): array
    {
        return $this->requestResponse($user, $query)->getData(true);
    }

    private function requestResponse(object $user, array $query = [])
    {
        $request = Request::create('/api/attendance', 'GET', $query);
        $request->attributes->set('workdesk_user', $user);

        return (new AttendanceController())->index($request);
    }
}
