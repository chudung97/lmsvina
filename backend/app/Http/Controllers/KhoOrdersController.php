<?php

namespace App\Http\Controllers;

use App\Support\KhoInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Đơn đặt hàng: Chờ duyệt → Đã duyệt (quản lý kho) → Đã đặt hàng → nhận hàng ở Nhập kho
 * (gắn mã PO) → Đã nhận một phần/Hoàn tất (tự động). PO đang mở được tính vào
 * "Hàng đang đặt" nên kế hoạch mua không đề xuất trùng.
 */
class KhoOrdersController extends KhoController
{
    private const MANUAL_STATUSES = ['pending_approval', 'approved', 'ordered', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'view')) {
            return $denied;
        }
        [$data, , $ref, $computed] = $this->state();
        $rows = array_map(function (array $order) use ($computed): array {
            $item = $computed['items'][$order['itemId']] ?? null;
            $received = $computed['orderReceived'][$order['id']] ?? 0;

            return [
                ...$order,
                ...$this->itemSummary($item),
                'price' => (float) ($item['price'] ?? 0),
                'budget' => $order['qty'] * (float) ($item['price'] ?? 0),
                'eta' => KhoInventory::addDays($order['date'], (int) ($item['lead'] ?? 0)),
                'received' => $received,
                'remaining' => in_array($order['status'], KhoInventory::OPEN_ORDER_STATUSES, true)
                    ? max(0, $order['qty'] - $received)
                    : 0,
            ];
        }, array_reverse($data['orders']));

        return response()->json(['ref' => $ref, 'rows' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $itemId = filter_var($request->input('itemId'), FILTER_VALIDATE_INT);
        $qty = filter_var($request->input('qty'), FILTER_VALIDATE_INT);
        $type = $request->input('type', 'periodic');
        $note = $this->cleanText($request->input('note'), 500);
        if (! $itemId || ! $qty || $qty < 1 || $qty > 1000000 || ! in_array($type, ['periodic', 'urgent'], true) || $note === null) {
            return $this->error('Kiểm tra mã hàng, số lượng và loại mua.', 400);
        }
        if (! DB::table('kho_items')->where('id', $itemId)->exists()) {
            return $this->error('Mã hàng không hợp lệ.', 400);
        }
        $poNo = DB::transaction(function () use ($request, $itemId, $qty, $type, $note): string {
            $this->lockKho();

            return $this->createOrder($request, $itemId, $qty, $type, $note ?: 'Lập thủ công');
        });
        $this->broadcastKho();

        return response()->json(['poNo' => $poNo], 201);
    }

    /** Tạo PO "Chờ duyệt" cho các mặt hàng kế hoạch mua đang đề xuất (hoặc danh sách itemIds). */
    public function fromPlan(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $itemIds = $request->input('itemIds');
        if ($itemIds !== null && (! is_array($itemIds) || count($itemIds) > 2000
            || array_filter($itemIds, fn ($id) => filter_var($id, FILTER_VALIDATE_INT) === false))) {
            return $this->error('Danh sách mã hàng không hợp lệ.', 400);
        }
        $created = DB::transaction(function () use ($request, $itemIds): array {
            $this->lockKho();
            $settings = $this->settings();
            $computed = KhoInventory::compute($this->loadData(), $settings, $this->refDate($settings));
            $wanted = $itemIds === null ? null : array_map('intval', $itemIds);
            $created = [];
            foreach ($computed['plan'] as $plan) {
                if ($plan['suggested'] <= 0 || ($wanted !== null && ! in_array($plan['itemId'], $wanted, true))) {
                    continue;
                }
                $created[] = $this->createOrder(
                    $request,
                    $plan['itemId'],
                    $plan['suggested'],
                    $plan['priority'] === 'urgent' ? 'urgent' : 'periodic',
                    'Tạo từ kế hoạch mua',
                );
            }

            return $created;
        });
        if (! $created) {
            return $this->error('Không có mặt hàng nào cần đặt mua.', 409);
        }
        $this->broadcastKho();

        return response()->json(['created' => $created], 201);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denyUnless($request, 'write')) {
            return $denied;
        }
        $status = $request->input('status');
        if (! in_array($status, self::MANUAL_STATUSES, true)) {
            return $this->error('Trạng thái PO không hợp lệ. "Đã nhận một phần" và "Hoàn tất" được cập nhật khi nhận hàng.', 400);
        }
        if ($status === 'approved' && ($denied = $this->denyUnless($request, 'manage'))) {
            return $this->error('Chỉ quản lý kho mới được duyệt đơn đặt hàng.', 403);
        }
        $order = DB::table('kho_purchase_orders')->where('id', (int) $id)->first();
        if (! $order) {
            return $this->error('Không tìm thấy đơn đặt hàng.', 404);
        }
        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return $this->error('Đơn đã hoàn tất hoặc đã hủy, không đổi trạng thái được.', 409);
        }
        $received = (int) DB::table('kho_receipts')->where('purchase_order_id', $order->id)->where('status', 'received')->sum('quantity');
        if ($received > 0 && $status !== 'cancelled') {
            return $this->error('Đơn đã nhận hàng; trạng thái được cập nhật tự động theo phiếu nhập.', 409);
        }
        if ($status === 'ordered' && $order->status === 'pending_approval') {
            return $this->error('Đơn cần được duyệt trước khi đặt hàng.', 409);
        }
        DB::table('kho_purchase_orders')->where('id', $order->id)->update([
            'status' => $status,
            'approved_by' => $status === 'approved' ? $this->user($request)->id : $order->approved_by,
            'updated_at' => now(),
        ]);
        $this->log($request, 'Đổi trạng thái PO', $order->po_no, "{$order->status} → {$status}");
        $this->broadcastKho();

        return response()->json(['ok' => true]);
    }

    private function createOrder(Request $request, int $itemId, int $qty, string $type, string $note): string
    {
        $prefix = 'PO'.substr($this->today(), 2, 2).'-';
        $max = 0;
        foreach (DB::table('kho_purchase_orders')->where('po_no', 'like', $prefix.'%')->pluck('po_no') as $value) {
            $max = max($max, (int) substr((string) $value, strlen($prefix)));
        }
        $poNo = $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
        DB::table('kho_purchase_orders')->insert([
            'po_no' => $poNo,
            'order_date' => $this->today(),
            'item_id' => $itemId,
            'quantity' => $qty,
            'status' => 'pending_approval',
            'order_type' => $type,
            'note' => $note,
            'created_by' => $this->user($request)->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->log($request, 'Tạo PO', $poNo, "hàng {$itemId} x{$qty}");

        return $poNo;
    }
}
