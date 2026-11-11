<?php

namespace Tests\Unit;

use App\Support\KhoInventory;
use PHPUnit\Framework\TestCase;

class KhoInventoryTest extends TestCase
{
    private function item(array $overrides = []): array
    {
        return [
            'id' => 1, 'code' => 'VPP-A4', 'name' => 'Giấy A4', 'group' => 'Văn phòng phẩm', 'unit' => 'Ream',
            'color' => '', 'size' => '', 'spec' => '', 'supplier' => '', 'price' => 100.0,
            'min' => 5, 'max' => 20, 'lead' => 7, 'cycle' => 0, 'perPerson' => 0, 'moq' => 5,
            'opening' => 10, 'family' => '', 'note' => '', 'active' => true,
            ...$overrides,
        ];
    }

    private function data(array $overrides = []): array
    {
        return [
            'items' => [$this->item()],
            'receipts' => [],
            'issues' => [],
            'orders' => [],
            'employees' => [],
            'knownUserIds' => [7],
            'departmentGroups' => [],
            'policies' => [],
            'families' => [],
            ...$overrides,
        ];
    }

    private function receipt(array $overrides = []): array
    {
        return ['id' => 1, 'voucher' => 'PN-1', 'date' => '2026-11-01', 'time' => '08:00:00', 'itemId' => 1, 'qty' => 5,
            'type' => 'purchase', 'poId' => null, 'status' => 'received', 'invoice' => '', 'unitPrice' => 200.0,
            'note' => '', 'receiver' => '', ...$overrides];
    }

    private function issue(array $overrides = []): array
    {
        return ['id' => 1, 'voucher' => 'PX-1', 'date' => '2026-11-02', 'time' => '09:00:00', 'userId' => 7, 'itemId' => 1,
            'qty' => 3, 'purpose' => 'periodic', 'status' => 'confirmed', 'note' => '', 'giver' => '',
            'createdDate' => '2026-11-02', ...$overrides];
    }

    public function test_month_arithmetic_clamps_to_the_last_day(): void
    {
        $this->assertSame('2027-02-28', KhoInventory::addMonths('2026-08-31', 6));
        $this->assertSame('2026-02-28', KhoInventory::addMonths('2026-05-31', -3));
        $this->assertSame(-3, KhoInventory::diffDays('2026-11-01', '2026-11-04'));
    }

    public function test_stock_uses_signed_receipts_and_only_confirmed_issues(): void
    {
        $result = KhoInventory::compute($this->data([
            'receipts' => [
                $this->receipt(),
                $this->receipt(['id' => 2, 'type' => 'damaged', 'qty' => 2]),
                $this->receipt(['id' => 3, 'qty' => 50, 'status' => 'cancelled']),
            ],
            'issues' => [
                $this->issue(),
                $this->issue(['id' => 2, 'status' => 'draft', 'date' => '', 'qty' => 1]),
                $this->issue(['id' => 3, 'status' => 'cancelled', 'qty' => 4]),
            ],
        ]), [], '2026-11-04');

        $this->assertSame(10, $result['stock'][1]);
        $this->assertSame(3, $result['used'][1]);
        // Bình quân gia quyền: (10 × 100 + 5 × 200) / 15
        $this->assertEqualsWithDelta(133.33, $result['price'][1], 0.01);
        $this->assertSame('safe', $result['stockRows'][1]['status']);
    }

    public function test_draft_exceeding_stock_is_blocked_and_cycle_overlap_needs_acknowledgement(): void
    {
        $uniform = $this->item(['id' => 2, 'code' => 'DP-POLO-M', 'cycle' => 12, 'perPerson' => 2, 'family' => 'DP-POLO', 'opening' => 5]);
        $result = KhoInventory::compute($this->data([
            'items' => [$this->item(), $uniform],
            'issues' => [
                $this->issue(['id' => 1, 'itemId' => 2, 'qty' => 1, 'date' => '2026-06-01']),
                $this->issue(['id' => 2, 'itemId' => 2, 'qty' => 1, 'status' => 'draft', 'date' => '', 'voucher' => 'PX-2']),
                $this->issue(['id' => 3, 'itemId' => 1, 'qty' => 99, 'status' => 'draft', 'date' => '', 'voucher' => 'PX-3']),
                $this->issue(['id' => 4, 'itemId' => 2, 'qty' => 3, 'status' => 'draft', 'date' => '', 'voucher' => 'PX-4', 'userId' => 8]),
                $this->issue(['id' => 5, 'itemId' => 2, 'qty' => 1, 'status' => 'draft', 'date' => '', 'voucher' => 'PX-5', 'purpose' => 'replacement']),
            ],
            'knownUserIds' => [7, 8],
        ]), [], '2026-11-04', true);

        $this->assertSame('ok', $result['issueWarnings'][1]);
        $this->assertSame('cycle_overlap', $result['issueWarnings'][2]);
        $this->assertSame('over_stock', $result['issueWarnings'][3]);
        $this->assertSame('over_quota', $result['issueWarnings'][4]);
        $this->assertSame('ok', $result['issueWarnings'][5]);
    }

    public function test_schedule_maps_employee_sizes_and_flags_missing_sizes(): void
    {
        $polo = $this->item(['id' => 2, 'code' => 'DP-POLO-M', 'cycle' => 12, 'perPerson' => 2, 'family' => 'DP-POLO', 'opening' => 1]);
        $employee = fn (int $id, string $shirt, string $hire) => [
            'id' => $id, 'code' => "NV{$id}", 'name' => "NV {$id}", 'departmentId' => 'dep-office', 'departmentName' => 'Văn phòng',
            'hireDate' => $hire, 'onLeave' => false, 'working' => true, 'shirtSize' => $shirt, 'pantsSize' => '', 'shoeSize' => '',
        ];
        $result = KhoInventory::compute($this->data([
            'items' => [$this->item(), $polo],
            'issues' => [$this->issue(['itemId' => 2, 'qty' => 2, 'date' => '2025-12-01'])],
            'employees' => [
                $employee(7, 'M', '2020-01-01'),
                $employee(8, '', '2020-01-01'),
                $employee(9, 'M', '2026-10-30'),
                [...$employee(10, 'M', '2020-01-01'), 'working' => false],
            ],
            'knownUserIds' => [7, 8, 9, 10],
            'departmentGroups' => ['dep-office' => 'Văn phòng'],
            'policies' => ['Văn phòng' => ['DP-POLO']],
            'families' => ['DP-POLO' => ['code' => 'DP-POLO', 'name' => 'Áo polo', 'sizeType' => 'shirt']],
        ]), ['scheduleStart' => '2026-11-01', 'remindDays' => 30], '2026-11-04');

        $rows = [];
        foreach ($result['schedule'] as $row) {
            $rows[$row['userId']] = $row;
        }
        $this->assertCount(3, $rows);
        $this->assertSame(1, $result['inactive']);
        $this->assertSame('2026-12-01', $rows[7]['due']);
        $this->assertSame('waiting_stock', $rows[7]['status']);
        $this->assertSame('missing_item', $rows[8]['status']);
        $this->assertTrue($rows[8]['missingSize']);
        // NV mới: ngày vào làm + 7 ngày chờ, không sớm hơn ngày bắt đầu áp dụng.
        $this->assertSame('2026-11-06', $rows[9]['due']);
    }

    public function test_purchase_plan_rounds_suggestion_up_to_moq_and_counts_open_orders(): void
    {
        $base = $this->data([
            'items' => [$this->item(['opening' => 2])],
            'issues' => [$this->issue(['qty' => 33, 'date' => '2026-10-01']), $this->issue(['id' => 2, 'qty' => 1, 'date' => '2026-10-02'])],
        ]);
        $base['items'][0]['opening'] = 40;

        // Dùng bq 34/3 ≈ 11.33/tháng → an toàn 6, ROP ≈ 8.64; tồn 6 ≤ ROP nên cần đặt.
        // SL = MAX(tối đa 20, dự báo 13.98 + 6) − 6 = 14 → làm tròn MOQ 5 = 15.
        $plan = KhoInventory::compute($base, [], '2026-11-04')['plan'][1];
        $this->assertSame(6, $plan['stock']);
        $this->assertSame(6, $plan['safety']);
        $this->assertTrue($plan['need']);
        $this->assertSame(15, $plan['suggested']);
        $this->assertSame('medium', $plan['priority']);
        $this->assertSame('2026-11-04', $plan['orderBy']);

        $withOrder = KhoInventory::compute([
            ...$base,
            'orders' => [['id' => 1, 'poNo' => 'PO26-0001', 'date' => '2026-11-01', 'itemId' => 1, 'qty' => 100, 'status' => 'ordered', 'type' => 'periodic', 'note' => '']],
        ], [], '2026-11-04')['plan'][1];
        $this->assertSame(100, $withOrder['onOrder']);
        $this->assertSame(0, $withOrder['suggested']);
    }
}
