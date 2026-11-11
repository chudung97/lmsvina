<?php

namespace App\Http\Controllers;

use App\Support\KhoInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Báo cáo quản trị theo kỳ tháng/quý/năm: nhập–xuất–tồn theo nhóm hàng, chi phí theo
 * phòng ban, top tiêu thụ, tồn lâu, chỉ số đáp ứng, ngân sách và danh sách đến kỳ.
 * Giá trị xuất tính theo đơn giá áp dụng hiện hành (không đóng băng giá lịch sử).
 */
class KhoReportsController extends KhoController
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [$data, $settings, $ref, $computed] = $this->state();
        $year = (int) ($request->query('year') ?: substr($ref, 0, 4));
        $period = $request->query('period', 'month');
        $number = (int) ($request->query('number') ?: (int) substr($ref, 5, 2));
        $departmentId = (string) ($request->query('departmentId') ?? '');
        $group = (string) ($request->query('group') ?? '');
        $itemCode = trim((string) ($request->query('itemCode') ?? ''));
        if ($year < 2000 || $year > 2100 || ! in_array($period, ['month', 'quarter', 'year'], true)
            || ($period === 'month' && ($number < 1 || $number > 12))
            || ($period === 'quarter' && ($number < 1 || $number > 4))) {
            return $this->error('Kỳ báo cáo không hợp lệ.', 400);
        }
        [$from, $to] = match ($period) {
            'month' => [sprintf('%04d-%02d-01', $year, $number), KhoInventory::addDays(KhoInventory::addMonths(sprintf('%04d-%02d-01', $year, $number), 1), -1)],
            'quarter' => [sprintf('%04d-%02d-01', $year, 3 * $number - 2), KhoInventory::addDays(KhoInventory::addMonths(sprintf('%04d-%02d-01', $year, 3 * $number - 2), 3), -1)],
            default => ["{$year}-01-01", "{$year}-12-31"],
        };

        $items = $computed['items'];
        $price = $computed['price'];
        $itemMatches = fn (?array $item): bool => $item !== null
            && ($group === '' || $item['group'] === $group)
            && ($itemCode === '' || $item['code'] === $itemCode);
        $receiptValue = fn (array $receipt): float => $receipt['qty'] * ($receipt['unitPrice'] ?? $items[$receipt['itemId']]['price']);
        $recipientIds = array_values(array_unique(array_column($computed['confirmed'], 'userId')));
        $recipientDepartments = $recipientIds
            ? DB::table('users')->whereIn('id', $recipientIds)->pluck('department_id', 'id')->all()
            : [];
        $departmentOfUser = fn (int $userId): ?string => isset($recipientDepartments[$userId])
            ? (string) $recipientDepartments[$userId]
            : null;

        // 1. Nhập – xuất – tồn theo nhóm hàng
        $flow = [];
        foreach (self::ITEM_GROUPS as $itemGroup) {
            if ($group !== '' && $group !== $itemGroup) {
                continue;
            }
            $ids = array_keys(array_filter($items, fn (array $item): bool => $item['group'] === $itemGroup && $itemMatches($item)));
            $set = array_flip($ids);
            $row = ['group' => $itemGroup, 'open' => 0.0, 'purchase' => 0.0, 'other' => 0.0, 'out' => 0.0, 'outQty' => 0];
            foreach ($ids as $id) {
                $row['open'] += $items[$id]['opening'] * $items[$id]['price'];
            }
            foreach ($data['receipts'] as $receipt) {
                if ($receipt['status'] !== 'received' || ! isset($set[$receipt['itemId']])) {
                    continue;
                }
                $signedValue = (KhoInventory::RECEIPT_SIGNS[$receipt['type']] ?? 0) * $receiptValue($receipt);
                if ($receipt['date'] < $from) {
                    $row['open'] += $signedValue;
                } elseif ($receipt['date'] <= $to) {
                    if ($receipt['type'] === 'purchase') {
                        $row['purchase'] += $signedValue;
                    } else {
                        $row['other'] += $signedValue;
                    }
                }
            }
            foreach ($computed['confirmed'] as $issue) {
                if (! isset($set[$issue['itemId']])) {
                    continue;
                }
                if ($issue['date'] < $from) {
                    $row['open'] -= $issue['qty'] * $price[$issue['itemId']];
                } elseif ($issue['date'] <= $to) {
                    $row['out'] += $issue['qty'] * $price[$issue['itemId']];
                    $row['outQty'] += $issue['qty'];
                }
            }
            $row['close'] = $row['open'] + $row['purchase'] + $row['other'] - $row['out'];
            $flow[] = $row;
        }

        // 2. Chi phí theo phòng ban
        $inRange = array_values(array_filter(
            $computed['confirmed'],
            fn (array $issue): bool => $issue['date'] >= $from && $issue['date'] <= $to && $itemMatches($items[$issue['itemId']] ?? null),
        ));
        $departments = DB::table('departments')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (object $department): bool => $departmentId === '' || (string) $department->id === $departmentId);
        $departmentRows = [];
        foreach ($departments as $department) {
            $id = (string) $department->id;
            $row = [
                'departmentId' => $id,
                'name' => $department->name,
                'headcount' => count(array_filter($data['employees'], fn (array $employee): bool => $employee['departmentId'] === $id && $employee['working'])),
                'supplies' => 0.0,
                'uniform' => 0.0,
                'safety' => 0.0,
            ];
            foreach ($inRange as $issue) {
                if ($departmentOfUser($issue['userId']) !== $id) {
                    continue;
                }
                $value = $issue['qty'] * $price[$issue['itemId']];
                $itemGroup = $items[$issue['itemId']]['group'];
                if ($itemGroup === 'Đồng phục') {
                    $row['uniform'] += $value;
                } elseif ($itemGroup === 'Bảo hộ lao động') {
                    $row['safety'] += $value;
                } else {
                    $row['supplies'] += $value;
                }
            }
            $scheduleRows = array_filter($computed['schedule'], fn (array $schedule): bool => $schedule['departmentId'] === $id);
            $row['total'] = $row['supplies'] + $row['uniform'] + $row['safety'];
            $row['perEmployee'] = $row['headcount'] ? ($row['uniform'] + $row['safety']) / $row['headcount'] : 0;
            $row['overdue'] = count(array_filter($scheduleRows, fn (array $schedule): bool => $schedule['status'] === 'overdue'));
            $row['soon'] = count(array_filter($scheduleRows, fn (array $schedule): bool => in_array($schedule['status'], ['due_soon', 'waiting_stock', 'due_today'], true)));
            $departmentRows[] = $row;
        }

        // 3. Top 10 tiêu thụ
        $quantities = [];
        foreach ($inRange as $issue) {
            if ($departmentId === '' || $departmentOfUser($issue['userId']) === $departmentId) {
                $quantities[$issue['itemId']] = ($quantities[$issue['itemId']] ?? 0) + $issue['qty'];
            }
        }
        arsort($quantities);
        $top = [];
        foreach (array_slice($quantities, 0, 10, true) as $itemId => $qty) {
            $top[] = [...$this->itemSummary($items[$itemId]), 'qty' => $qty, 'stock' => $computed['stock'][$itemId]];
        }

        // 4. Tồn lâu / tồn dư
        $staleDays = (int) $settings['staleDays'];
        $stale = array_values(array_filter(
            $computed['stockRows'],
            fn (array $row): bool => $row['stock'] > 0 && ($row['idleDays'] === null || $row['idleDays'] >= $staleDays)
                && $itemMatches($items[$row['itemId']]),
        ));
        usort($stale, fn (array $a, array $b): int => ($b['idleDays'] ?? PHP_INT_MAX) <=> ($a['idleDays'] ?? PHP_INT_MAX));
        $over = array_values(array_map(
            fn (array $row): string => $items[$row['itemId']]['code'],
            array_filter($computed['stockRows'], fn (array $row): bool => $row['status'] === 'over' && $itemMatches($items[$row['itemId']])),
        ));

        // 5. Chỉ số đáp ứng
        $confirmedLines = count(array_filter($data['issues'], fn (array $issue): bool => $issue['status'] === 'confirmed'
            && $issue['date'] >= $from && $issue['date'] <= $to));
        $shortageLines = count(array_filter($data['issues'], fn (array $issue): bool => $issue['status'] === 'shortage'
            && $issue['createdDate'] >= $from && $issue['createdDate'] <= $to));
        $urgentValue = 0.0;
        foreach ($data['orders'] as $order) {
            if ($order['type'] === 'urgent' && $order['status'] !== 'cancelled' && $order['date'] >= $from && $order['date'] <= $to) {
                $urgentValue += $order['qty'] * ($items[$order['itemId']]['price'] ?? 0);
            }
        }

        // 6. Ngân sách vs thực tế
        $budgets = DB::table('kho_budgets')->where('budget_year', $year)->pluck('amount', 'budget_month');
        $actual = array_fill(1, 12, 0.0);
        foreach ($data['receipts'] as $receipt) {
            if ($receipt['status'] === 'received' && $receipt['type'] === 'purchase' && str_starts_with($receipt['date'], $year.'-')) {
                $actual[(int) substr($receipt['date'], 5, 2)] += $receiptValue($receipt);
            }
        }
        $budgetRows = [];
        for ($month = 1; $month <= 12; $month++) {
            $budgetRows[] = ['label' => "Tháng {$month}", 'budget' => (float) ($budgets[$month] ?? 0), 'actual' => $actual[$month], 'bold' => false];
        }
        for ($quarter = 0; $quarter < 4; $quarter++) {
            $slice = array_slice($budgetRows, $quarter * 3, 3);
            $budgetRows[] = [
                'label' => 'Quý '.($quarter + 1),
                'budget' => array_sum(array_column($slice, 'budget')),
                'actual' => array_sum(array_column($slice, 'actual')),
                'bold' => true,
            ];
        }
        $months = array_slice($budgetRows, 0, 12);
        $budgetRows[] = ['label' => 'CẢ NĂM', 'budget' => array_sum(array_column($months, 'budget')), 'actual' => array_sum(array_column($months, 'actual')), 'bold' => true];

        // 7. Đến kỳ / quá hạn
        $due = array_values(array_filter(
            $computed['schedule'],
            fn (array $row): bool => in_array($row['status'], ['overdue', 'due_today', 'due_soon', 'waiting_stock'], true)
                && ($departmentId === '' || $row['departmentId'] === $departmentId),
        ));
        usort($due, fn (array $a, array $b): int => strcmp($a['due'], $b['due']));

        return response()->json([
            'ref' => $ref,
            'from' => $from,
            'to' => $to,
            'flow' => $flow,
            'departments' => $departmentRows,
            'top' => $top,
            'stale' => array_map(fn (array $row): array => [...$row, ...$this->itemSummary($items[$row['itemId']])], array_slice($stale, 0, 10)),
            'staleDays' => $staleDays,
            'over' => $over,
            'kpis' => [
                'confirmedLines' => $confirmedLines,
                'shortageLines' => $shortageLines,
                'fulfillment' => $confirmedLines + $shortageLines ? round($confirmedLines / ($confirmedLines + $shortageLines) * 100, 1) : null,
                'urgentValue' => $urgentValue,
                'outValue' => array_sum(array_column($flow, 'out')),
            ],
            'budget' => $budgetRows,
            'due' => array_slice($due, 0, 200),
            'dueTotal' => count($due),
        ]);
    }
}
