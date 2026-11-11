<?php

namespace App\Http\Controllers;

use App\Support\KhoInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KhoOverviewController extends KhoController
{
    private const DUE_STATUSES = ['overdue', 'due_today', 'due_soon', 'waiting_stock'];

    public function me(Request $request): JsonResponse
    {
        $role = $this->khoRole($this->user($request));

        return response()->json([
            'role' => $role,
            'canView' => $role !== null,
            'canWrite' => in_array($role, ['manager', 'keeper'], true),
            'canManage' => $role === 'manager',
            'itemGroups' => self::ITEM_GROUPS,
            'purposes' => KhoInventory::PURPOSES,
            'receiptTypes' => array_keys(KhoInventory::RECEIPT_SIGNS),
            'orderStatuses' => KhoInventory::ORDER_STATUSES,
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [$data, $settings, $ref, $computed] = $this->state();
        $items = $computed['items'];
        $monthStart = substr($ref, 0, 8).'01';
        $statusCount = fn (string $status): int => count(array_filter(
            $computed['stockRows'],
            fn (array $row): bool => $row['status'] === $status,
        ));
        $under = $statusCount('below_min') + $statusCount('out') + $statusCount('negative');
        $inMonth = 0;
        foreach ($data['receipts'] as $receipt) {
            if ($receipt['status'] === 'received' && $receipt['type'] === 'purchase'
                && $receipt['date'] >= $monthStart && $receipt['date'] <= $ref) {
                $inMonth += $receipt['qty'];
            }
        }
        $outMonth = 0;
        foreach ($computed['confirmed'] as $issue) {
            if ($issue['date'] >= $monthStart && $issue['date'] <= $ref) {
                $outMonth += $issue['qty'];
            }
        }
        $remind = (int) $settings['remindDays'];
        $soonEmployees = 0;
        $overdueEmployees = 0;
        foreach ($computed['nextDue'] as $due) {
            if ($due < $ref) {
                $overdueEmployees++;
            } elseif (KhoInventory::diffDays($due, $ref) <= $remind) {
                $soonEmployees++;
            }
        }
        $need = array_filter($computed['plan'], fn (array $plan): bool => $plan['suggested'] > 0);
        $waiting = count(array_filter($computed['schedule'], fn (array $row): bool => $row['status'] === 'waiting_stock'));

        $windows = [-1, 7, 15, 30];
        $dueCounts = [];
        $buyCounts = [];
        $orderCounts = [];
        foreach ($windows as $window) {
            $limit = $window < 0 ? $ref : KhoInventory::addDays($ref, $window);
            $dueCounts[] = count(array_filter(
                $computed['schedule'],
                fn (array $row): bool => $row['due'] !== '' && $row['due'] <= $limit,
            ));
            $buyCounts[] = count(array_filter(
                $computed['plan'],
                fn (array $plan): bool => $plan['orderBy'] !== '' && $plan['orderBy'] <= $limit,
            ));
            $orderCounts[] = count(array_filter($data['orders'], function (array $order) use ($computed, $items, $ref, $window, $limit): bool {
                if (! in_array($order['status'], KhoInventory::OPEN_ORDER_STATUSES, true)
                    || $order['qty'] - ($computed['orderReceived'][$order['id']] ?? 0) <= 0) {
                    return false;
                }
                $eta = KhoInventory::addDays($order['date'], (int) ($items[$order['itemId']]['lead'] ?? 0));

                return $window < 0 ? $eta < $ref : ($eta >= $ref && $eta <= $limit);
            }));
        }

        $dueRows = array_values(array_filter(
            $computed['schedule'],
            fn (array $row): bool => in_array($row['status'], self::DUE_STATUSES, true)
                && $row['due'] <= KhoInventory::addDays($ref, 30),
        ));
        usort($dueRows, fn (array $a, array $b): int => strcmp($a['due'], $b['due']));
        $priorityOrder = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'none' => 3];
        $buyRows = array_values($need);
        usort($buyRows, fn (array $a, array $b): int => ($priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']])
            ?: ($b['budget'] <=> $a['budget']));

        $valueByGroup = [];
        foreach (self::ITEM_GROUPS as $group) {
            $valueByGroup[] = [
                'group' => $group,
                'value' => array_sum(array_map(
                    fn (array $row): float => $row['value'],
                    array_filter($computed['stockRows'], fn (array $row): bool => $items[$row['itemId']]['group'] === $group),
                )),
            ];
        }

        return response()->json([
            'ref' => $ref,
            'kpis' => [
                'itemCount' => count($items),
                'stockValue' => array_sum(array_column($computed['stockRows'], 'value')),
                'inMonth' => $inMonth,
                'outMonth' => $outMonth,
                'low' => $statusCount('low'),
                'under' => $under,
                'needBuy' => count($need),
                'over' => $statusCount('over'),
                'soonEmployees' => $soonEmployees,
                'overdueEmployees' => $overdueEmployees,
                'budget' => array_sum(array_column($need, 'budget')),
                'waiting' => $waiting,
            ],
            'tasks' => [
                ['label' => 'Cấp phát đến hạn (dòng NV × mặt hàng)', 'values' => $dueCounts],
                ['label' => 'Đặt mua theo "ngày cần đặt"', 'values' => $buyCounts],
                ['label' => 'PO dự kiến về kho', 'values' => $orderCounts],
                ['label' => 'Mặt hàng dưới tối thiểu cần xử lý ngay', 'values' => [$under, $under, $under, $under]],
            ],
            'valueByGroup' => $valueByGroup,
            'statusCounts' => array_map(
                fn (string $status): array => ['status' => $status, 'count' => $statusCount($status)],
                ['safe', 'low', 'below_min', 'out', 'over', 'negative'],
            ),
            'dueRows' => array_slice($dueRows, 0, 15),
            'dueTotal' => count($dueRows),
            'buyRows' => array_map(
                fn (array $plan): array => [...$plan, ...$this->itemSummary($items[$plan['itemId']])],
                array_slice($buyRows, 0, 10),
            ),
            'buyTotal' => count($buyRows),
        ]);
    }

    public function stock(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [, , $ref, $computed] = $this->state();
        $rows = [];
        foreach ($computed['stockRows'] as $id => $row) {
            $item = $computed['items'][$id];
            $rows[] = [
                ...$row,
                ...$this->itemSummary($item),
                'min' => $item['min'],
                'max' => $item['max'],
                'active' => $item['active'],
                'price' => $computed['price'][$id],
                'suggested' => $computed['plan'][$id]['suggested'] ?? 0,
            ];
        }

        return response()->json(['ref' => $ref, 'rows' => $rows]);
    }

    public function schedule(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [, $settings, $ref, $computed] = $this->state();
        $reminders = DB::table('kho_reminders')->get()->mapWithKeys(fn (object $row): array => [
            $row->reminder_key => ['date' => substr((string) $row->reminded_on, 0, 10), 'result' => $row->result],
        ])->all();
        $rows = array_map(fn (array $row): array => [...$row, 'reminder' => $reminders[$row['key']] ?? null], $computed['schedule']);
        usort($rows, fn (array $a, array $b): int => strcmp($a['due'] ?: '9999', $b['due'] ?: '9999'));

        return response()->json([
            'ref' => $ref,
            'inactive' => $computed['inactive'],
            'remindDays' => (int) $settings['remindDays'],
            'remindGap' => (int) $settings['remindGap'],
            'emailTo' => (string) $settings['emailTo'],
            'rows' => $rows,
        ]);
    }

    public function plan(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [, , $ref, $computed] = $this->state();
        $rows = array_map(
            fn (array $plan): array => [...$plan, ...$this->itemSummary($computed['items'][$plan['itemId']])],
            array_values($computed['plan']),
        );

        return response()->json([
            'ref' => $ref,
            'rows' => $rows,
            'totalBudget' => array_sum(array_map(fn (array $plan): float => $plan['budget'], $rows)),
        ]);
    }

    /** Màn hình "Cấp phát của tôi" dành cho mọi tài khoản đã đăng nhập. */
    public function mine(Request $request): JsonResponse
    {
        $userId = (int) $this->user($request)->id;
        [, , $ref, $computed] = $this->state();
        $issues = DB::table('kho_issues')
            ->join('kho_items', 'kho_items.id', '=', 'kho_issues.item_id')
            ->where('kho_issues.recipient_user_id', $userId)
            ->where('kho_issues.status', '!=', 'cancelled')
            ->orderByDesc('kho_issues.issue_date')
            ->orderByDesc('kho_issues.id')
            ->limit(500)
            ->get([
                'kho_issues.id',
                'kho_issues.voucher_no as voucher',
                'kho_issues.issue_date as date',
                'kho_issues.issue_time as time',
                'kho_issues.quantity as qty',
                'kho_issues.purpose',
                'kho_issues.status',
                'kho_items.code as itemCode',
                'kho_items.name as itemName',
                'kho_items.unit',
                'kho_items.size',
            ]);

        return response()->json([
            'ref' => $ref,
            'issues' => $issues,
            'schedule' => array_values(array_filter(
                $computed['schedule'],
                fn (array $row): bool => $row['userId'] === $userId,
            )),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }

        return response()->json(DB::table('kho_logs')
            ->leftJoin('users', 'users.id', '=', 'kho_logs.user_id')
            ->orderByDesc('kho_logs.id')
            ->limit(500)
            ->get([
                'kho_logs.id',
                'kho_logs.created_at as createdAt',
                'kho_logs.action',
                'kho_logs.reference',
                'kho_logs.detail',
                'users.name as userName',
            ]));
    }

    public function markReminders(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $keys = $request->input('keys');
        if (! is_array($keys) || ! $keys || count($keys) > 5000
            || array_filter($keys, fn ($key) => ! is_string($key) || ! preg_match('/^\d+\|[^|]{1,40}$/', $key))) {
            return $this->error('Danh sách dòng nhắc lịch không hợp lệ.', 400);
        }
        $result = $this->cleanText($request->input('result', 'Đã nhắc (email thủ công)'), 160);
        if ($result === null) {
            return $this->error('Kết quả nhắc lịch tối đa 160 ký tự.', 400);
        }
        $today = $this->today();
        DB::table('kho_reminders')->upsert(
            array_map(fn (string $key): array => [
                'reminder_key' => $key,
                'reminded_on' => $today,
                'result' => $result,
                'reminded_by' => $this->user($request)->id,
            ], array_values(array_unique($keys))),
            ['reminder_key'],
            ['reminded_on', 'result', 'reminded_by'],
        );
        $this->log($request, 'Đánh dấu đã nhắc', '', count($keys).' dòng');
        $this->broadcastKho();

        return response()->json(['ok' => true]);
    }
}
