<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Tính toán kho thuần (không truy cập DB): tồn kho, đơn giá áp dụng, hàng đang đặt,
 * lịch cấp phát định kỳ, kế hoạch mua và cảnh báo giao dịch.
 *
 * Tồn hiện tại = Tồn đầu + Σ nhập ròng (phiếu "received", có dấu theo loại)
 *              − Σ xuất (phiếu "confirmed"). Tồn không được lưu, luôn tính lại.
 */
class KhoInventory
{
    public const RECEIPT_SIGNS = [
        'purchase' => 1,
        'employee_return' => 1,
        'adjust_plus' => 1,
        'supplier_return' => -1,
        'damaged' => -1,
        'adjust_minus' => -1,
    ];

    public const PURPOSES = ['new_hire', 'periodic', 'supplement', 'replacement', 'department_supply'];

    public const ORDER_STATUSES = ['pending_approval', 'approved', 'ordered', 'partial', 'completed', 'cancelled'];

    public const OPEN_ORDER_STATUSES = ['pending_approval', 'approved', 'ordered', 'partial'];

    public const BLOCKING_WARNINGS = ['invalid_ref', 'qty_invalid', 'missing_date', 'duplicate', 'over_stock'];

    public const ACKNOWLEDGE_WARNINGS = ['over_quota', 'cycle_overlap'];

    public const DEFAULT_SETTINGS = [
        'refDate' => '',
        'valuation' => 'avg',
        'remindDays' => 30,
        'safetyDays' => 14,
        'reviewDays' => 30,
        'avgMonths' => 3,
        'firstWait' => 7,
        'soonFactor' => 1.2,
        'staleDays' => 180,
        'overFactor' => 1,
        'remindGap' => 7,
        'scheduleStart' => '',
        'emailTo' => '',
        'receiverName' => '',
        'giverName' => '',
        'stocktakeName' => '',
        'approverName' => '',
    ];

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable(substr($date, 0, 10), new DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '+' : '').$days.' days')
            ->format('Y-m-d');
    }

    public static function addMonths(string $date, int $months): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', substr($date, 0, 10)));
        $total = $year * 12 + ($month - 1) + $months;
        $newYear = intdiv($total, 12);
        $newMonth = $total % 12 + 1;
        $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $newYear, $newMonth)))->format('t');

        return sprintf('%04d-%02d-%02d', $newYear, $newMonth, min($day, $lastDay));
    }

    /** Số ngày từ $b đến $a (a − b). */
    public static function diffDays(string $a, string $b): int
    {
        $utc = new DateTimeZone('UTC');
        $first = new DateTimeImmutable(substr($a, 0, 10), $utc);
        $second = new DateTimeImmutable(substr($b, 0, 10), $utc);

        return (int) round(($first->getTimestamp() - $second->getTimestamp()) / 86400);
    }

    public static function roundUpTo(float $value, int $step): int
    {
        $step = max(1, $step);

        return (int) (ceil(round(max($value, 0) / $step, 9)) * $step);
    }

    public static function stockStatus(int $quantity, array $item, array $settings): string
    {
        $min = (int) $item['min'];
        $max = (int) $item['max'];

        return match (true) {
            $quantity < 0 => 'negative',
            $quantity === 0 => 'out',
            $quantity < $min => 'below_min',
            $quantity <= $min * (float) $settings['soonFactor'] => 'low',
            $max > 0 && $quantity > $max * (float) $settings['overFactor'] => 'over',
            default => 'safe',
        };
    }

    /**
     * $data gồm: items, receipts, issues, orders, employees, knownUserIds,
     * departmentGroups (departmentId => nhóm), policies (nhóm => [mã dòng SP]),
     * families (mã => ['sizeType' => ...]).
     *
     * Với $warningsOnly = true chỉ tính tồn và cảnh báo (dùng khi xác nhận hàng loạt).
     */
    public static function compute(array $data, array $settings, string $ref, bool $warningsOnly = false): array
    {
        $settings = [...self::DEFAULT_SETTINGS, ...$settings];
        $items = [];
        $itemByCode = [];
        foreach ($data['items'] as $item) {
            $items[$item['id']] = $item;
            $itemByCode[$item['code']] = $item['id'];
        }

        $stock = [];
        $used = [];
        $buyQty = [];
        $buyValue = [];
        $netIn = [];
        foreach ($items as $id => $item) {
            $stock[$id] = (int) $item['opening'];
            $used[$id] = 0;
            $buyQty[$id] = 0;
            $buyValue[$id] = 0.0;
            $netIn[$id] = 0;
        }

        $orderReceived = [];
        foreach ($data['receipts'] as $receipt) {
            if ($receipt['status'] !== 'received' || ! isset($items[$receipt['itemId']])) {
                continue;
            }
            $itemId = $receipt['itemId'];
            $signed = (self::RECEIPT_SIGNS[$receipt['type']] ?? 0) * (int) $receipt['qty'];
            $stock[$itemId] += $signed;
            $netIn[$itemId] += $signed;
            if ($receipt['type'] === 'purchase') {
                $buyQty[$itemId] += (int) $receipt['qty'];
                $buyValue[$itemId] += $receipt['qty'] * ($receipt['unitPrice'] ?? $items[$itemId]['price']);
            }
            if ($receipt['poId']) {
                $orderReceived[$receipt['poId']] = ($orderReceived[$receipt['poId']] ?? 0) + $signed;
            }
        }

        $confirmed = [];
        $lastUse = [];
        $byEmployeeFamily = [];
        foreach ($data['issues'] as $issue) {
            if ($issue['status'] !== 'confirmed' || ! isset($items[$issue['itemId']])) {
                continue;
            }
            $itemId = $issue['itemId'];
            $stock[$itemId] -= (int) $issue['qty'];
            $used[$itemId] += (int) $issue['qty'];
            $confirmed[] = $issue;
            $date = (string) ($issue['date'] ?? '');
            if ($date !== '' && (! isset($lastUse[$itemId]) || $date > $lastUse[$itemId])) {
                $lastUse[$itemId] = $date;
            }
            $family = $items[$itemId]['family'];
            if ($family !== '' && $date !== '') {
                $byEmployeeFamily[$issue['userId'].'|'.$family][] = $issue;
            }
        }
        foreach ($byEmployeeFamily as &$history) {
            usort($history, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        }
        unset($history);

        $price = [];
        foreach ($items as $id => $item) {
            $quantity = (int) $item['opening'] + $buyQty[$id];
            $price[$id] = $settings['valuation'] === 'avg' && $quantity > 0
                ? ((int) $item['opening'] * (float) $item['price'] + $buyValue[$id]) / $quantity
                : (float) $item['price'];
        }

        $onOrder = [];
        foreach ($data['orders'] as $order) {
            if (in_array($order['status'], self::OPEN_ORDER_STATUSES, true)) {
                $remaining = max(0, (int) $order['qty'] - ($orderReceived[$order['id']] ?? 0));
                $onOrder[$order['itemId']] = ($onOrder[$order['itemId']] ?? 0) + $remaining;
            }
        }

        $result = [
            'ref' => $ref,
            'settings' => $settings,
            'items' => $items,
            'stock' => $stock,
            'used' => $used,
            'netIn' => $netIn,
            'price' => $price,
            'lastUse' => $lastUse,
            'onOrder' => $onOrder,
            'orderReceived' => $orderReceived,
            'confirmed' => $confirmed,
        ];
        $result['issueContext'] = self::issueContext($data, $items, $stock, $byEmployeeFamily, $ref);
        $result['issueWarnings'] = [];
        foreach ($data['issues'] as $issue) {
            $result['issueWarnings'][$issue['id']] = self::issueWarning($issue, $result['issueContext']);
        }
        if ($warningsOnly) {
            return $result;
        }

        $result['receiptWarnings'] = self::receiptWarnings($data, $items, $orderReceived);
        [$schedule, $inactive] = self::schedule($data, $items, $itemByCode, $stock, $byEmployeeFamily, $settings, $ref);
        $result['schedule'] = $schedule;
        $result['inactive'] = $inactive;
        $result['nextDue'] = [];
        foreach ($schedule as $row) {
            if ($row['due'] !== '' && (! isset($result['nextDue'][$row['userId']]) || $row['due'] < $result['nextDue'][$row['userId']])) {
                $result['nextDue'][$row['userId']] = $row['due'];
            }
        }
        $result['lastIssue'] = [];
        foreach ($confirmed as $issue) {
            if ((int) $items[$issue['itemId']]['cycle'] > 0
                && (! isset($result['lastIssue'][$issue['userId']]) || $issue['date'] > $result['lastIssue'][$issue['userId']])) {
                $result['lastIssue'][$issue['userId']] = $issue['date'];
            }
        }
        $result['plan'] = self::plan($data, $items, $stock, $onOrder, $price, $confirmed, $schedule, $settings, $ref);
        $result['stockRows'] = [];
        foreach ($items as $id => $item) {
            $quantity = $stock[$id];
            $result['stockRows'][$id] = [
                'itemId' => $id,
                'opening' => (int) $item['opening'],
                'netIn' => $netIn[$id],
                'out' => $used[$id],
                'stock' => $quantity,
                'status' => self::stockStatus($quantity, $item, $settings),
                'value' => $quantity * $price[$id],
                'lastUse' => $lastUse[$id] ?? '',
                'idleDays' => isset($lastUse[$id]) ? self::diffDays($ref, $lastUse[$id]) : null,
                'onOrder' => $onOrder[$id] ?? 0,
            ];
        }

        return $result;
    }

    private static function firstDue(array $employee, array $settings, string $ref): string
    {
        $base = ! empty($employee['hireDate'])
            ? self::addDays($employee['hireDate'], (int) $settings['firstWait'])
            : '';
        $start = (string) ($settings['scheduleStart'] ?? '');
        if ($start !== '' && ($base === '' || $base < $start)) {
            $base = $start;
        }

        return $base === '' ? $ref : $base;
    }

    private static function schedule(
        array $data,
        array $items,
        array $itemByCode,
        array $stock,
        array $byEmployeeFamily,
        array $settings,
        string $ref,
    ): array {
        $remind = (int) $settings['remindDays'];
        $rows = [];
        $inactive = 0;
        foreach ($data['employees'] as $employee) {
            if (! $employee['working']) {
                $inactive++;

                continue;
            }
            $group = $data['departmentGroups'][(string) ($employee['departmentId'] ?? '')] ?? null;
            foreach ($group === null ? [] : ($data['policies'][$group] ?? []) as $familyCode) {
                $family = $data['families'][$familyCode] ?? null;
                if (! $family) {
                    continue;
                }
                $size = match ($family['sizeType']) {
                    'shirt' => (string) $employee['shirtSize'],
                    'pants' => (string) $employee['pantsSize'],
                    'shoes' => (string) $employee['shoeSize'],
                    default => '',
                };
                $needsSize = $family['sizeType'] !== 'none';
                $code = $familyCode.($needsSize && $size !== '' ? '-'.$size : '');
                $itemId = $needsSize && $size === '' ? null : ($itemByCode[$code] ?? null);
                $item = $itemId !== null ? $items[$itemId] : null;
                $history = $byEmployeeFamily[$employee['id'].'|'.$familyCode] ?? [];
                $last = $history ? $history[count($history) - 1]['date'] : '';
                $cycle = $item ? (int) $item['cycle'] : 0;
                $row = [
                    'key' => $employee['id'].'|'.$familyCode,
                    'userId' => $employee['id'],
                    'userCode' => $employee['code'],
                    'name' => $employee['name'],
                    'departmentId' => $employee['departmentId'],
                    'departmentName' => $employee['departmentName'],
                    'family' => $familyCode,
                    'itemCode' => $code,
                    'itemId' => $itemId,
                    'itemName' => $item['name'] ?? '',
                    'missingSize' => $needsSize && $size === '',
                    'last' => $last,
                    'cycle' => $cycle,
                    'due' => '',
                    'left' => null,
                    'qty' => $item ? (int) $item['perPerson'] : 0,
                    'available' => $itemId !== null ? $stock[$itemId] : 0,
                    'status' => '',
                ];
                if (! $item) {
                    $row['status'] = 'missing_item';
                } elseif ($cycle <= 0) {
                    $row['status'] = 'not_configured';
                } else {
                    $row['due'] = $last !== '' ? self::addMonths($last, $cycle) : self::firstDue($employee, $settings, $ref);
                    $row['left'] = self::diffDays($row['due'], $ref);
                    $row['status'] = match (true) {
                        $row['left'] <= $remind && $row['available'] < $row['qty'] => 'waiting_stock',
                        $row['left'] < 0 => 'overdue',
                        $row['left'] === 0 => 'due_today',
                        $row['left'] <= $remind => 'due_soon',
                        default => 'not_due',
                    };
                }
                $rows[] = $row;
            }
        }

        return [$rows, $inactive];
    }

    private static function plan(
        array $data,
        array $items,
        array $stock,
        array $onOrder,
        array $price,
        array $confirmed,
        array $schedule,
        array $settings,
        string $ref,
    ): array {
        $avgMonths = max(1, (int) $settings['avgMonths']);
        $since = self::addMonths($ref, -$avgMonths);
        $usage = [];
        foreach ($confirmed as $issue) {
            if ($issue['date'] > $since && $issue['date'] <= $ref) {
                $usage[$issue['itemId']] = ($usage[$issue['itemId']] ?? 0) + (int) $issue['qty'];
            }
        }
        $scheduleByItem = [];
        foreach ($schedule as $row) {
            if ($row['itemId'] !== null && $row['due'] !== '') {
                $scheduleByItem[$row['itemId']][] = $row;
            }
        }
        $ordersByItem = [];
        foreach ($data['orders'] as $order) {
            $ordersByItem[$order['itemId']][] = $order['status'];
        }

        $plan = [];
        foreach ($items as $id => $item) {
            if (! $item['active']) {
                continue;
            }
            $lead = (int) $item['lead'];
            $review = (int) $settings['reviewDays'];
            $monthly = ($usage[$id] ?? 0) / $avgMonths;
            $safety = (int) ceil(round($monthly * (int) $settings['safetyDays'] / 30, 9));
            $rows = $scheduleByItem[$id] ?? [];
            $hasSchedule = (int) $item['cycle'] > 0 && $rows;
            $demand = fn (int $horizon): int => count(array_filter(
                $rows,
                fn (array $row): bool => self::diffDays($row['due'], $ref) <= $horizon,
            )) * (int) $item['perPerson'];
            $needLead = $hasSchedule ? $demand($lead) : $monthly * $lead / 30;
            $forecast = $hasSchedule ? $demand($lead + $review) : $monthly * ($lead + $review) / 30;
            $ordered = $onOrder[$id] ?? 0;
            $current = $stock[$id];
            $position = $current + $ordered;
            $reorderPoint = $needLead + $safety;
            $need = $position <= $reorderPoint || $position < (int) $item['min'];
            $suggested = $need
                ? self::roundUpTo(max((int) $item['max'], $forecast + $safety) - $position, max(1, (int) $item['moq']))
                : 0;
            $perDay = $monthly / 30;
            $orderBy = $need
                ? $ref
                : ($perDay > 0 ? self::addDays($ref, (int) floor(($position - $reorderPoint) / $perDay)) : '');
            $priority = match (true) {
                $suggested === 0 => 'none',
                $current <= 0 => 'urgent',
                $current < (int) $item['min'] || $current <= $monthly * $lead / 30 => 'high',
                default => 'medium',
            };
            $statuses = $ordersByItem[$id] ?? [];
            $purchaseStatus = '';
            foreach (['partial', 'ordered', 'approved', 'pending_approval'] as $status) {
                if (in_array($status, $statuses, true)) {
                    $purchaseStatus = $status;
                    break;
                }
            }
            if ($purchaseStatus === '' && $suggested > 0) {
                $purchaseStatus = 'not_proposed';
            }

            $plan[$id] = [
                'itemId' => $id,
                'stock' => $current,
                'min' => (int) $item['min'],
                'max' => (int) $item['max'],
                'safety' => $safety,
                'monthly' => $monthly,
                'needLead' => $needLead,
                'forecast' => $forecast,
                'reorderPoint' => $reorderPoint,
                'onOrder' => $ordered,
                'lead' => $lead,
                'suggested' => $suggested,
                'price' => (float) $item['price'],
                'budget' => $suggested * (float) $item['price'],
                'orderBy' => $orderBy,
                'priority' => $priority,
                'purchaseStatus' => $purchaseStatus,
                'need' => $need,
            ];
        }

        return $plan;
    }

    /**
     * Ngữ cảnh để đánh giá cảnh báo từng dòng xuất. Khi xác nhận hàng loạt, controller cập
     * nhật dần 'stock' và 'byEmployeeFamily' sau mỗi dòng thay vì tính lại toàn bộ.
     */
    private static function issueContext(array $data, array $items, array $stock, array $byEmployeeFamily, string $ref): array
    {
        $duplicates = [];
        foreach ($data['issues'] as $issue) {
            if ($issue['status'] !== 'cancelled' && $issue['voucher'] !== '') {
                $key = $issue['voucher'].'|'.$issue['userId'].'|'.$issue['itemId'];
                $duplicates[$key] = ($duplicates[$key] ?? 0) + 1;
            }
        }

        return [
            'items' => $items,
            'stock' => $stock,
            'byEmployeeFamily' => $byEmployeeFamily,
            'duplicates' => $duplicates,
            'known' => array_flip(array_map('strval', $data['knownUserIds'] ?? [])),
            'ref' => $ref,
        ];
    }

    public static function issueWarning(array $issue, array $context): string
    {
        $item = $context['items'][$issue['itemId']] ?? null;
        $stock = $context['stock'];
        $date = (string) ($issue['date'] ?? '');
        if (! $item || ! isset($context['known'][(string) $issue['userId']])) {
            return 'invalid_ref';
        }
        if ((int) $issue['qty'] <= 0) {
            return 'qty_invalid';
        }
        if ($issue['status'] === 'cancelled') {
            return 'cancelled';
        }
        if ($issue['status'] === 'confirmed' && $date === '') {
            return 'missing_date';
        }
        if (($context['duplicates'][$issue['voucher'].'|'.$issue['userId'].'|'.$issue['itemId']] ?? 0) > 1) {
            return 'duplicate';
        }
        if ($issue['status'] === 'confirmed'
            ? $stock[$issue['itemId']] < 0
            : $stock[$issue['itemId']] < (int) $issue['qty']) {
            return 'over_stock';
        }
        if ((int) $item['perPerson'] > 0 && (int) $issue['qty'] > (int) $item['perPerson']
            && $issue['purpose'] !== 'department_supply') {
            return 'over_quota';
        }
        if ((int) $item['cycle'] > 0 && $issue['purpose'] !== 'replacement' && $item['family'] !== '') {
            $reference = $date !== '' ? $date : $context['ref'];
            foreach ($context['byEmployeeFamily'][$issue['userId'].'|'.$item['family']] ?? [] as $previous) {
                if ($previous['id'] !== $issue['id'] && $previous['date'] < $reference
                    && self::addMonths($previous['date'], (int) $item['cycle']) > $reference) {
                    return 'cycle_overlap';
                }
            }
        }

        return 'ok';
    }

    private static function receiptWarnings(array $data, array $items, array $orderReceived): array
    {
        $orders = [];
        foreach ($data['orders'] as $order) {
            $orders[$order['id']] = $order;
        }
        $invoices = [];
        foreach ($data['receipts'] as $receipt) {
            if ($receipt['status'] === 'received' && $receipt['invoice'] !== '') {
                $key = $receipt['invoice'].'|'.$receipt['itemId'].'|'.$receipt['qty'];
                $invoices[$key] = ($invoices[$key] ?? 0) + 1;
            }
        }

        $warnings = [];
        foreach ($data['receipts'] as $receipt) {
            $warning = 'ok';
            if (! isset($items[$receipt['itemId']])) {
                $warning = 'invalid_item';
            } elseif ((int) $receipt['qty'] <= 0) {
                $warning = 'qty_invalid';
            } elseif ($receipt['status'] === 'cancelled') {
                $warning = 'cancelled';
            } elseif ($receipt['poId'] && ! isset($orders[$receipt['poId']])) {
                $warning = 'po_missing';
            } elseif ($receipt['poId'] && ($orderReceived[$receipt['poId']] ?? 0) > (int) $orders[$receipt['poId']]['qty']) {
                $warning = 'over_po';
            } elseif ($receipt['invoice'] !== ''
                && ($invoices[$receipt['invoice'].'|'.$receipt['itemId'].'|'.$receipt['qty']] ?? 0) > 1) {
                $warning = 'dup_invoice';
            }
            $warnings[$receipt['id']] = $warning;
        }

        return $warnings;
    }
}
