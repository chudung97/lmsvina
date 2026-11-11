<?php

namespace App\Http\Controllers;

use App\Support\KhoInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Xuất kho/cấp phát và nhập kho/điều chỉnh tồn.
 *
 * Phiếu xuất được lập ở trạng thái draft (chưa trừ tồn). Ngày giờ giao dịch chỉ được
 * ghi khi xác nhận và là thời điểm thật của máy chủ. Không xóa giao dịch: chỉ hủy
 * kèm lý do để còn dấu vết; tồn tự hoàn lại vì luôn được tính lại từ giao dịch.
 */
class KhoTransactionsController extends KhoController
{
    public function issues(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [$from, $to] = $this->range($request);
        if ($from === null) {
            return $this->error('Khoảng ngày không hợp lệ.', 400);
        }
        [$data, , $ref, $computed] = $this->state();
        $items = $computed['items'];
        $rows = array_values(array_filter($data['issues'], fn (array $issue): bool => in_array($issue['status'], ['draft', 'shortage'], true)
            || ($issue['date'] !== '' ? $issue['date'] : $issue['createdDate']) >= $from
            && ($issue['date'] !== '' ? $issue['date'] : $issue['createdDate']) <= $to));
        $users = $this->userNames(array_column($rows, 'userId'));

        return response()->json([
            'ref' => $ref,
            'from' => $from,
            'to' => $to,
            'rows' => array_map(function (array $issue) use ($items, $users, $computed): array {
                $item = $items[$issue['itemId']] ?? null;
                $user = $users[$issue['userId']] ?? null;

                return [
                    ...$issue,
                    ...$this->itemSummary($item),
                    'userCode' => $user['code'] ?? '',
                    'userName' => $user['name'] ?? '',
                    'departmentName' => $user['departmentName'] ?? '',
                    'cycle' => (int) ($item['cycle'] ?? 0),
                    'nextDue' => $issue['status'] === 'confirmed' && $item && $item['cycle'] > 0
                        ? KhoInventory::addMonths($issue['date'], $item['cycle'])
                        : '',
                    'warning' => $computed['issueWarnings'][$issue['id']] ?? 'ok',
                ];
            }, array_reverse($rows)),
        ]);
    }

    public function storeIssue(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $userId = filter_var($request->input('userId'), FILTER_VALIDATE_INT);
        $purpose = $request->input('purpose');
        $giver = $this->cleanText($request->input('giver'), 120);
        $note = $this->cleanText($request->input('note'), 500);
        $lines = $request->input('items');
        if (! $userId || ! in_array($purpose, KhoInventory::PURPOSES, true) || $giver === null || $note === null) {
            return $this->error('Kiểm tra người nhận, mục đích, người giao và ghi chú.', 400);
        }
        if (! is_array($lines) || ! $lines || count($lines) > 50) {
            return $this->error('Phiếu cần từ 1 đến 50 mặt hàng.', 400);
        }
        $clean = [];
        foreach ($lines as $line) {
            $itemId = filter_var($line['itemId'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($line['qty'] ?? null, FILTER_VALIDATE_INT);
            if (! $itemId || ! $qty || $qty < 1 || $qty > 100000) {
                return $this->error('Mỗi dòng cần mã hàng hợp lệ và số lượng là số nguyên lớn hơn 0.', 400);
            }
            $clean[] = ['itemId' => $itemId, 'qty' => $qty];
        }
        $recipient = DB::table('users')->where('id', $userId)->first(['id', 'name', 'active']);
        if (! $recipient || ! $recipient->active) {
            return $this->error('Người nhận không tồn tại hoặc không còn hoạt động.', 400);
        }
        $activeItems = DB::table('kho_items')->whereIn('id', array_column($clean, 'itemId'))->where('active', 1)->pluck('id')->all();
        if (count(array_unique(array_column($clean, 'itemId'))) !== count($activeItems)) {
            return $this->error('Có mã hàng không tồn tại hoặc đã ngừng sử dụng.', 400);
        }

        $actor = $this->user($request);
        [$voucher, $ids] = DB::transaction(function () use ($clean, $userId, $purpose, $giver, $note, $actor): array {
            $this->lockKho();
            $voucher = $this->nextVoucher('kho_issues', 'voucher_no', 'PX');
            $ids = [];
            $now = now();
            foreach ($clean as $line) {
                $ids[] = DB::table('kho_issues')->insertGetId([
                    'voucher_no' => $voucher,
                    'recipient_user_id' => $userId,
                    'item_id' => $line['itemId'],
                    'quantity' => $line['qty'],
                    'purpose' => $purpose,
                    'status' => 'draft',
                    'note' => $note,
                    'giver_name' => $giver,
                    'created_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [$voucher, $ids];
        });
        $this->log($request, 'Lập phiếu nháp', $voucher, "NV {$userId}: ".count($ids).' dòng');

        $confirmation = null;
        if ($request->boolean('confirm')) {
            $confirmation = $this->confirm($request, $ids, $request->boolean('acknowledge'));
        }
        $this->broadcastKho([$userId]);

        return response()->json(['voucher' => $voucher, 'ids' => $ids, 'confirmation' => $confirmation], 201);
    }

    public function confirmIssues(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $ids = $request->input('ids');
        if (! is_array($ids) || ! $ids || count($ids) > 1000
            || array_filter($ids, fn ($id) => filter_var($id, FILTER_VALIDATE_INT) === false)) {
            return $this->error('Danh sách dòng cần xác nhận không hợp lệ.', 400);
        }
        $result = $this->confirm($request, array_map('intval', $ids), $request->boolean('acknowledge'));
        $this->broadcastKho(DB::table('kho_issues')->whereIn('id', $result['confirmed'])->pluck('recipient_user_id')->all());

        return response()->json($result);
    }

    /**
     * Xác nhận lần lượt từng dòng, tính lại tồn/cảnh báo sau mỗi dòng.
     * Cảnh báo đỏ chặn; cảnh báo vàng (vượt định mức, trùng kỳ) cần acknowledge.
     */
    private function confirm(Request $request, array $ids, bool $acknowledge): array
    {
        $actor = $this->user($request);

        return DB::transaction(function () use ($request, $ids, $acknowledge, $actor): array {
            $this->lockKho();
            $data = $this->loadData();
            $settings = $this->settings();
            $ref = $this->refDate($settings);
            $index = [];
            foreach ($data['issues'] as $position => $issue) {
                $index[$issue['id']] = $position;
            }
            // Tính một lần, sau mỗi dòng xác nhận chỉ cập nhật tồn và lịch sử cấp phát liên quan.
            $context = KhoInventory::compute($data, $settings, $ref, true)['issueContext'];
            $confirmed = [];
            $blocked = [];
            $needsAcknowledge = [];
            foreach (array_values(array_unique($ids)) as $id) {
                if (! isset($index[$id])) {
                    continue;
                }
                $issue = $data['issues'][$index[$id]];
                if (! in_array($issue['status'], ['draft', 'shortage'], true)) {
                    continue;
                }
                $warning = KhoInventory::issueWarning($issue, $context);
                $line = ['id' => $id, 'voucher' => $issue['voucher'], 'itemId' => $issue['itemId'], 'userId' => $issue['userId'], 'warning' => $warning];
                if (in_array($warning, KhoInventory::BLOCKING_WARNINGS, true)) {
                    $blocked[] = $line;

                    continue;
                }
                if (in_array($warning, KhoInventory::ACKNOWLEDGE_WARNINGS, true) && ! $acknowledge) {
                    $needsAcknowledge[] = $line;

                    continue;
                }
                $date = $this->today();
                $time = $this->nowTime();
                $note = $issue['note'];
                if ($warning !== 'ok') {
                    $note = ($note !== '' ? $note.' | ' : '')."[Xác nhận dù cảnh báo: {$warning}]";
                }
                DB::table('kho_issues')->where('id', $id)->whereIn('status', ['draft', 'shortage'])->update([
                    'status' => 'confirmed',
                    'issue_date' => $date,
                    'issue_time' => $time,
                    'note' => mb_substr($note, 0, 1000),
                    'confirmed_by' => $actor->id,
                    'updated_at' => now(),
                ]);
                $confirmedIssue = [...$issue, 'status' => 'confirmed', 'date' => $date, 'time' => $time, 'note' => $note];
                $data['issues'][$index[$id]] = $confirmedIssue;
                if (isset($context['stock'][$issue['itemId']])) {
                    $context['stock'][$issue['itemId']] -= $issue['qty'];
                    $family = $context['items'][$issue['itemId']]['family'];
                    if ($family !== '') {
                        $context['byEmployeeFamily'][$issue['userId'].'|'.$family][] = $confirmedIssue;
                    }
                }
                $this->log($request, 'Xác nhận cấp phát', $issue['voucher'], "#{$id} NV {$issue['userId']} hàng {$issue['itemId']} x{$issue['qty']} lúc {$date} {$time}");
                $confirmed[] = $id;
            }

            return ['confirmed' => $confirmed, 'blocked' => $this->describe($blocked), 'needsAcknowledge' => $this->describe($needsAcknowledge)];
        });
    }

    private function describe(array $lines): array
    {
        if (! $lines) {
            return [];
        }
        $items = DB::table('kho_items')->whereIn('id', array_column($lines, 'itemId'))->pluck('code', 'id');
        $users = $this->userNames(array_column($lines, 'userId'));

        return array_map(fn (array $line): array => [
            ...$line,
            'itemCode' => $items[$line['itemId']] ?? '',
            'userName' => $users[$line['userId']]['name'] ?? '',
        ], $lines);
    }

    public function markShortage(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $updated = DB::table('kho_issues')->where('id', (int) $id)->where('status', 'draft')
            ->update(['status' => 'shortage', 'updated_at' => now()]);
        if (! $updated) {
            return $this->error('Chỉ dòng nháp mới chuyển được sang "Thiếu hàng".', 409);
        }
        $this->log($request, 'Đánh dấu thiếu hàng', (string) $id);
        $this->broadcastKho();

        return response()->json(['ok' => true]);
    }

    public function cancelIssue(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $reason = $this->cleanText($request->input('reason'), 300);
        if (! $reason) {
            return $this->error('Cần ghi lý do hủy (tối đa 300 ký tự).', 400);
        }
        $issue = DB::table('kho_issues')->where('id', (int) $id)->first();
        if (! $issue) {
            return $this->error('Không tìm thấy dòng cấp phát.', 404);
        }
        if ($issue->status === 'cancelled') {
            return $this->error('Dòng này đã được hủy.', 409);
        }
        if ($issue->status === 'confirmed' && ($denied = $this->denyUnless($request, 'manage'))) {
            return $this->error('Chỉ quản lý kho được hủy dòng đã xác nhận (hủy sẽ hoàn lại tồn).', 403);
        }
        $stamp = Carbon::now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');
        DB::table('kho_issues')->where('id', $issue->id)->update([
            'status' => 'cancelled',
            'note' => mb_substr(($issue->note !== '' ? $issue->note.' | ' : '')."[Hủy {$stamp}: {$reason}]", 0, 1000),
            'cancelled_by' => $this->user($request)->id,
            'cancelled_at' => now(),
            'updated_at' => now(),
        ]);
        $this->log($request, 'Hủy cấp phát', $issue->voucher_no, "#{$issue->id} {$reason}");
        $this->broadcastKho([$issue->recipient_user_id]);

        return response()->json(['ok' => true]);
    }

    public function receipts(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [$from, $to] = $this->range($request);
        if ($from === null) {
            return $this->error('Khoảng ngày không hợp lệ.', 400);
        }
        [$data, , $ref, $computed] = $this->state();
        $orders = array_column($data['orders'], 'poNo', 'id');
        $rows = array_values(array_filter($data['receipts'], fn (array $receipt): bool => $receipt['date'] >= $from && $receipt['date'] <= $to));

        return response()->json([
            'ref' => $ref,
            'from' => $from,
            'to' => $to,
            'rows' => array_map(function (array $receipt) use ($computed, $orders): array {
                $item = $computed['items'][$receipt['itemId']] ?? null;
                $price = $receipt['unitPrice'] ?? ($item['price'] ?? 0);

                return [
                    ...$receipt,
                    ...$this->itemSummary($item),
                    'poNo' => $receipt['poId'] ? ($orders[$receipt['poId']] ?? '') : '',
                    'appliedPrice' => $price,
                    'amount' => $receipt['qty'] * $price,
                    'warning' => $computed['receiptWarnings'][$receipt['id']] ?? 'ok',
                ];
            }, array_reverse($rows)),
        ]);
    }

    public function storeReceipt(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $itemId = filter_var($request->input('itemId'), FILTER_VALIDATE_INT);
        $qty = filter_var($request->input('qty'), FILTER_VALIDATE_INT);
        $type = $request->input('type');
        $poId = $request->input('poId') === null || $request->input('poId') === ''
            ? null
            : filter_var($request->input('poId'), FILTER_VALIDATE_INT);
        $invoice = $this->cleanText($request->input('invoice'), 60);
        $note = $this->cleanText($request->input('note'), 500);
        $date = $request->input('date') ?: $this->today();
        $unitPrice = $request->input('unitPrice');
        if (! $itemId || ! $qty || $qty < 1 || $qty > 1000000 || ! array_key_exists((string) $type, KhoInventory::RECEIPT_SIGNS)
            || $poId === false || $invoice === null || $note === null || ! $this->validDate($date)
            || ($unitPrice !== null && $unitPrice !== '' && (! is_numeric($unitPrice) || $unitPrice < 0))) {
            return $this->error('Kiểm tra mã hàng, số lượng (số nguyên > 0), loại giao dịch, ngày và đơn giá.', 400);
        }
        if ($date > $this->today()) {
            return $this->error('Ngày nhập không được sau hôm nay.', 400);
        }
        if (in_array($type, ['damaged', 'adjust_plus', 'adjust_minus', 'supplier_return'], true) && $note === '') {
            return $this->error('Cần ghi chú/biên bản cho loại giao dịch này.', 400);
        }
        if ($poId !== null && $type !== 'purchase') {
            return $this->error('Chỉ giao dịch "Nhập mua" mới gắn với đơn đặt hàng.', 400);
        }
        if (! DB::table('kho_items')->where('id', $itemId)->exists()) {
            return $this->error('Mã hàng không hợp lệ.', 400);
        }

        $actor = $this->user($request);
        $settings = $this->settings();
        $result = DB::transaction(function () use ($request, $itemId, $qty, $type, $poId, $invoice, $note, $date, $unitPrice, $actor, $settings): array {
            $this->lockKho();
            $order = null;
            if ($poId !== null) {
                $order = DB::table('kho_purchase_orders')->where('id', $poId)->lockForUpdate()->first();
                if (! $order || ! in_array($order->status, ['approved', 'ordered', 'partial'], true) || (int) $order->item_id !== $itemId) {
                    return ['error' => 'Đơn đặt hàng chưa được duyệt, đã đóng hoặc không chứa mã hàng này.', 'status' => 400];
                }
            }
            if (KhoInventory::RECEIPT_SIGNS[$type] < 0) {
                $computed = KhoInventory::compute($this->loadData(), $settings, $this->refDate($settings), true);
                $current = $computed['stock'][$itemId] ?? 0;
                if ($current < $qty && ! $request->boolean('allowNegative')) {
                    return ['error' => "Tồn hiện tại {$current} nhỏ hơn {$qty}. Xác nhận lại nếu vẫn muốn ghi nhận (tồn sẽ âm).", 'status' => 409, 'needsConfirm' => true];
                }
            }
            $voucher = $this->nextVoucher('kho_receipts', 'voucher_no', 'PN');
            $id = DB::table('kho_receipts')->insertGetId([
                'voucher_no' => $voucher,
                'receipt_date' => $date,
                'receipt_time' => $this->nowTime(),
                'item_id' => $itemId,
                'quantity' => $qty,
                'receipt_type' => $type,
                'purchase_order_id' => $poId,
                'status' => 'received',
                'invoice_no' => $invoice,
                'unit_price' => $unitPrice === null || $unitPrice === '' ? null : (float) $unitPrice,
                'note' => $note,
                'receiver_name' => (string) ($settings['receiverName'] ?: $actor->name),
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($order) {
                $this->refreshOrderStatus($order->id);
            }

            return ['id' => $id, 'voucher' => $voucher];
        });
        if (isset($result['error'])) {
            return response()->json(['error' => $result['error'], 'needsConfirm' => $result['needsConfirm'] ?? false], $result['status']);
        }
        $this->log($request, 'Nhập kho', $result['voucher'], "{$type} hàng {$itemId} x{$qty}".($poId ? " PO {$poId}" : ''));
        $this->broadcastKho();

        return response()->json($result, 201);
    }

    public function cancelReceipt(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'manage')) {
            return $denied;
        }
        $reason = $this->cleanText($request->input('reason'), 300);
        if (! $reason) {
            return $this->error('Cần ghi lý do hủy (tối đa 300 ký tự).', 400);
        }
        $receipt = DB::table('kho_receipts')->where('id', (int) $id)->first();
        if (! $receipt) {
            return $this->error('Không tìm thấy giao dịch nhập.', 404);
        }
        if ($receipt->status !== 'received') {
            return $this->error('Giao dịch này đã được hủy.', 409);
        }
        DB::transaction(function () use ($request, $receipt, $reason): void {
            $this->lockKho();
            $stamp = Carbon::now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');
            DB::table('kho_receipts')->where('id', $receipt->id)->update([
                'status' => 'cancelled',
                'note' => mb_substr(($receipt->note !== '' ? $receipt->note.' | ' : '')."[Hủy {$stamp}: {$reason}]", 0, 500),
                'cancelled_by' => $this->user($request)->id,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);
            if ($receipt->purchase_order_id) {
                $this->refreshOrderStatus((int) $receipt->purchase_order_id);
            }
        });
        $this->log($request, 'Hủy nhập kho', $receipt->voucher_no, "#{$receipt->id} {$reason}");
        $this->broadcastKho();

        return response()->json(['ok' => true]);
    }

    /** Cập nhật trạng thái PO theo số lượng đã nhận (bỏ qua PO hủy/chưa đặt). */
    private function refreshOrderStatus(int $orderId): void
    {
        $order = DB::table('kho_purchase_orders')->where('id', $orderId)->first();
        if (! $order || in_array($order->status, ['cancelled', 'pending_approval'], true)) {
            return;
        }
        $received = (int) DB::table('kho_receipts')
            ->where('purchase_order_id', $orderId)
            ->where('status', 'received')
            ->where('receipt_type', 'purchase')
            ->sum('quantity');
        $status = $received >= (int) $order->quantity ? 'completed' : ($received > 0 ? 'partial' : 'ordered');
        if ($order->status === 'approved' && $received === 0) {
            $status = 'approved';
        }
        DB::table('kho_purchase_orders')->where('id', $orderId)->update(['status' => $status, 'updated_at' => now()]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function range(Request $request): array
    {
        $to = $request->query('to') ?: $this->refDate($this->settings());
        $from = $request->query('from') ?: KhoInventory::addDays($to, -90);
        if (! $this->validDate($from) || ! $this->validDate($to) || $from > $to) {
            return [null, null];
        }

        return [$from, $to];
    }
}
