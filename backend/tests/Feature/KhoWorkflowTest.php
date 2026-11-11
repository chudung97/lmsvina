<?php

namespace Tests\Feature;

use App\Http\Controllers\KhoItemsController;
use App\Http\Controllers\KhoOrdersController;
use App\Http\Controllers\KhoOverviewController;
use App\Http\Controllers\KhoSettingsController;
use App\Http\Controllers\KhoTransactionsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KhoWorkflowTest extends TestCase
{
    private object $migration;

    private object $admin;

    private object $keeper;

    private object $employee;

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
            $table->dateTime('NgayVaoLamViec')->nullable();
            $table->boolean('NghiViecTamThoi')->nullable();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });
        $this->migration = require base_path('database/migrations/2026_11_04_000001_create_kho_tables.php');
        $this->migration->up();

        DB::table('departments')->insert(['id' => 'dep-office', 'name' => 'Văn phòng']);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'System admin', 'user_code' => 'AD01', 'role' => 'admin', 'org_level' => 'employee', 'department_id' => null, 'NgayVaoLamViec' => null],
            ['id' => 2, 'name' => 'Thủ kho', 'user_code' => 'NV02', 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dep-office', 'NgayVaoLamViec' => '2020-01-01 00:00:00'],
            ['id' => 3, 'name' => 'Nhân viên', 'user_code' => 'NV03', 'role' => 'member', 'org_level' => 'employee', 'department_id' => 'dep-office', 'NgayVaoLamViec' => '2020-01-01 00:00:00'],
        ]);
        DB::table('kho_staff')->insert(['user_id' => 2, 'staff_role' => 'keeper', 'assigned_by' => 1]);

        $this->admin = (object) ['id' => 1, 'name' => 'System admin', 'role' => 'admin', 'org_level' => 'employee'];
        $this->keeper = (object) ['id' => 2, 'name' => 'Thủ kho', 'role' => 'member', 'org_level' => 'employee'];
        $this->employee = (object) ['id' => 3, 'name' => 'Nhân viên', 'role' => 'member', 'org_level' => 'employee'];
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_only_kho_staff_can_open_management_screens(): void
    {
        $overview = new KhoOverviewController;

        $this->assertSame(403, $overview->dashboard($this->request($this->employee))->getStatusCode());
        $this->assertFalse($overview->me($this->request($this->employee))->getData(true)['canView']);
        $this->assertSame(200, $overview->mine($this->request($this->employee))->getStatusCode());
        $this->assertSame('keeper', $overview->me($this->request($this->keeper))->getData(true)['role']);
        $this->assertSame('manager', $overview->me($this->request($this->admin))->getData(true)['role']);
        $this->assertSame(200, $overview->dashboard($this->request($this->keeper))->getStatusCode());
        $this->assertSame(403, (new KhoItemsController)->store($this->request($this->keeper, ['code' => 'X']))->getStatusCode());
    }

    public function test_issue_is_confirmed_with_real_timestamp_and_blocked_when_stock_is_short(): void
    {
        $itemId = $this->createItem(['code' => 'VPP-A4', 'name' => 'Giấy A4', 'group' => 'Văn phòng phẩm', 'unit' => 'Ream']);
        $transactions = new KhoTransactionsController;
        $receipt = $transactions->storeReceipt($this->request($this->keeper, ['itemId' => $itemId, 'qty' => 10, 'type' => 'purchase']));
        $this->assertSame(201, $receipt->getStatusCode());

        $issued = $transactions->storeIssue($this->request($this->keeper, [
            'userId' => 3, 'purpose' => 'department_supply', 'items' => [['itemId' => $itemId, 'qty' => 3]], 'confirm' => true,
        ]))->getData(true);
        $this->assertCount(1, $issued['confirmation']['confirmed']);
        $row = DB::table('kho_issues')->first();
        $this->assertSame('confirmed', $row->status);
        $this->assertNotNull($row->issue_date);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $row->issue_time);

        $blocked = $transactions->storeIssue($this->request($this->keeper, [
            'userId' => 3, 'purpose' => 'department_supply', 'items' => [['itemId' => $itemId, 'qty' => 20]], 'confirm' => true,
        ]))->getData(true);
        $this->assertSame([], $blocked['confirmation']['confirmed']);
        $this->assertSame('over_stock', $blocked['confirmation']['blocked'][0]['warning']);

        $stock = collect((new KhoOverviewController)->stock($this->request($this->keeper))->getData(true)['rows'])->firstWhere('itemId', $itemId);
        $this->assertSame(7, $stock['stock']);
    }

    public function test_batch_confirmation_tracks_stock_used_by_earlier_lines(): void
    {
        $itemId = $this->createItem(['code' => 'VPP-BUT', 'name' => 'Bút bi', 'group' => 'Văn phòng phẩm', 'unit' => 'Cây', 'opening' => 5, 'max' => 50]);
        $transactions = new KhoTransactionsController;
        $first = $transactions->storeIssue($this->request($this->keeper, [
            'userId' => 3, 'purpose' => 'department_supply', 'items' => [['itemId' => $itemId, 'qty' => 4]],
        ]))->getData(true);
        $second = $transactions->storeIssue($this->request($this->keeper, [
            'userId' => 2, 'purpose' => 'department_supply', 'items' => [['itemId' => $itemId, 'qty' => 4]],
        ]))->getData(true);

        $result = $transactions->confirmIssues($this->request($this->keeper, [
            'ids' => [...$first['ids'], ...$second['ids']],
        ]))->getData(true);

        $this->assertSame($first['ids'], $result['confirmed']);
        $this->assertSame('over_stock', $result['blocked'][0]['warning']);
        $this->assertSame('draft', DB::table('kho_issues')->where('id', $second['ids'][0])->value('status'));
    }

    public function test_cycle_overlap_requires_acknowledgement_and_only_managers_cancel_confirmed_lines(): void
    {
        $itemId = $this->createItem([
            'code' => 'DP-POLO-M', 'name' => 'Áo polo', 'group' => 'Đồng phục', 'unit' => 'Cái',
            'size' => 'M', 'family' => 'DP-POLO', 'cycle' => 12, 'perPerson' => 2, 'opening' => 10, 'max' => 20,
        ]);
        $transactions = new KhoTransactionsController;
        $first = $transactions->storeIssue($this->request($this->keeper, [
            'userId' => 3, 'purpose' => 'periodic', 'items' => [['itemId' => $itemId, 'qty' => 2]], 'confirm' => true,
        ]))->getData(true);
        DB::table('kho_issues')->where('id', $first['ids'][0])->update(['issue_date' => '2026-01-15']);

        $second = $transactions->storeIssue($this->request($this->keeper, [
            'userId' => 3, 'purpose' => 'supplement', 'items' => [['itemId' => $itemId, 'qty' => 1]], 'confirm' => true,
        ]))->getData(true);
        $this->assertSame('cycle_overlap', $second['confirmation']['needsAcknowledge'][0]['warning']);

        $acknowledged = $transactions->confirmIssues($this->request($this->keeper, [
            'ids' => $second['ids'], 'acknowledge' => true,
        ]))->getData(true);
        $this->assertSame($second['ids'], $acknowledged['confirmed']);
        $this->assertStringContainsString('cycle_overlap', DB::table('kho_issues')->where('id', $second['ids'][0])->value('note'));

        $keeperCancel = $transactions->cancelIssue($this->request($this->keeper, ['reason' => 'Lập nhầm']), (string) $second['ids'][0]);
        $this->assertSame(403, $keeperCancel->getStatusCode());
        $adminCancel = $transactions->cancelIssue($this->request($this->admin, ['reason' => 'Lập nhầm']), (string) $second['ids'][0]);
        $this->assertSame(200, $adminCancel->getStatusCode());
        $stock = collect((new KhoOverviewController)->stock($this->request($this->keeper))->getData(true)['rows'])->firstWhere('itemId', $itemId);
        $this->assertSame(8, $stock['stock']);
    }

    public function test_purchase_order_needs_manager_approval_and_follows_receipts(): void
    {
        $itemId = $this->createItem(['code' => 'IN-HP78A', 'name' => 'Mực in', 'group' => 'Vật tư máy in', 'unit' => 'Hộp']);
        $orders = new KhoOrdersController;
        $transactions = new KhoTransactionsController;
        $this->assertSame(201, $orders->store($this->request($this->keeper, ['itemId' => $itemId, 'qty' => 4]))->getStatusCode());
        $order = DB::table('kho_purchase_orders')->first();
        $this->assertSame('pending_approval', $order->status);

        $early = $transactions->storeReceipt($this->request($this->keeper, ['itemId' => $itemId, 'qty' => 1, 'type' => 'purchase', 'poId' => $order->id]));
        $this->assertSame(400, $early->getStatusCode());
        $this->assertSame(403, $orders->updateStatus($this->request($this->keeper, ['status' => 'approved']), (string) $order->id)->getStatusCode());
        $this->assertSame(200, $orders->updateStatus($this->request($this->admin, ['status' => 'approved']), (string) $order->id)->getStatusCode());

        $transactions->storeReceipt($this->request($this->keeper, ['itemId' => $itemId, 'qty' => 1, 'type' => 'purchase', 'poId' => $order->id]));
        $this->assertSame('partial', DB::table('kho_purchase_orders')->value('status'));
        $transactions->storeReceipt($this->request($this->keeper, ['itemId' => $itemId, 'qty' => 3, 'type' => 'purchase', 'poId' => $order->id]));
        $this->assertSame('completed', DB::table('kho_purchase_orders')->value('status'));
    }

    public function test_schedule_uses_department_policy_and_employee_sizes(): void
    {
        $this->createItem([
            'code' => 'DP-POLO-L', 'name' => 'Áo polo', 'group' => 'Đồng phục', 'unit' => 'Cái',
            'size' => 'L', 'family' => 'DP-POLO', 'cycle' => 12, 'perPerson' => 2, 'opening' => 10, 'max' => 20,
        ]);
        $settings = (new KhoSettingsController)->show($this->request($this->admin))->getData(true);
        $saved = (new KhoSettingsController)->update($this->request($this->admin, [
            'settings' => [...$settings['settings'], 'scheduleStart' => '2026-11-01', 'refDate' => '2026-11-04'],
            'families' => [['code' => 'DP-POLO', 'name' => 'Áo polo', 'sizeType' => 'shirt']],
            'policies' => ['Văn phòng' => ['DP-POLO']],
            'departmentGroups' => [['departmentId' => 'dep-office', 'group' => 'Văn phòng']],
        ]));
        $this->assertSame(200, $saved->getStatusCode());
        $sizes = (new KhoSettingsController)->updateSizes($this->request($this->keeper, ['shirtSize' => 'l']), '3');
        $this->assertSame(200, $sizes->getStatusCode());

        $rows = collect((new KhoOverviewController)->schedule($this->request($this->keeper))->getData(true)['rows'])->keyBy('userId');
        $this->assertSame('DP-POLO-L', $rows[3]['itemCode']);
        $this->assertSame('2026-11-01', $rows[3]['due']);
        $this->assertSame('overdue', $rows[3]['status']);
        $this->assertSame('missing_item', $rows[2]['status']);
        $this->assertSame(1, collect((new KhoOverviewController)->mine($this->request($this->employee))->getData(true)['schedule'])->count());
    }

    private function createItem(array $values): int
    {
        $response = (new KhoItemsController)->store($this->request($this->admin, $values));
        $this->assertSame(201, $response->getStatusCode(), json_encode($response->getData(true), JSON_UNESCAPED_UNICODE));

        return (int) $response->getData(true)['id'];
    }

    private function request(object $user, array $input = []): Request
    {
        $request = Request::create('/api/kho', 'POST', $input);
        $request->attributes->set('workdesk_user', $user);

        return $request;
    }
}
