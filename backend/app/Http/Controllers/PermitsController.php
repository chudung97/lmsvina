<?php

namespace App\Http\Controllers;

use App\Support\WorkdeskBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PermitsController extends ApiController
{
    public function users(Request $request): JsonResponse
    {
        $users = DB::table('users')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->where('users.active', 1)
            ->where('users.id', '!=', $this->user($request)->id)
            ->orderBy('users.name')
            ->get(['users.id', 'users.user_code', 'users.name', 'departments.name as department_name']);

        return response()->json($users->map(fn ($user) => [
                'id' => $this->responseUserId($user->id),
                'user_code' => $user->user_code,
                'name' => $user->name,
                'departmentName' => $user->department_name,
            ]));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->permitQuery()
            ->where(function ($query) use ($request): void {
                $userId = $this->user($request)->id;
                $query->where('gate_permits.requester_id', $userId)
                    ->orWhere('gate_permits.approver_id', $userId)
                    ->orWhere('gate_permits.guard_id', $userId)
                    ->orWhereExists(function ($subquery) use ($userId): void {
                        $subquery->selectRaw('1')
                            ->from('gate_permit_approval_steps')
                            ->whereColumn('gate_permit_approval_steps.permit_id', 'gate_permits.id')
                            ->where('gate_permit_approval_steps.user_id', $userId);
                    });
            })
            ->orderByDesc('gate_permits.created_at')
            ->orderByDesc('gate_permits.id')
            ->get()
            ->pipe(fn ($permits) => $this->responsePermitUsers($permits)));
    }

    public function store(Request $request): JsonResponse
    {
        $direction = $request->input('direction');
        $date = $request->input('date');
        $time = $request->input('time');
        $returnTime = $request->input('returnTime');
        $purpose = is_string($request->input('purpose')) ? trim($request->input('purpose')) : '';
        $destination = is_string($request->input('destination')) ? trim($request->input('destination')) : '';
        $vehicleInfo = is_string($request->input('vehicleInfo')) ? trim($request->input('vehicleInfo')) : '';
        $approverIds = $request->input('approverIds');
        $hrId = $request->input('hrId');
        $guardId = $request->input('guardId');
        $requesterId = (string) $this->user($request)->id;

        if (! in_array($direction, ['out', 'in'], true)) {
            return $this->error('Chọn loại phiếu ra cổng hoặc vào cổng.', 400);
        }
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || ! $this->validTime($time)
            || ($returnTime !== null && $returnTime !== '' && ! $this->validTime($returnTime))) {
            return $this->error('Ngày hoặc thời gian trên phiếu không hợp lệ.', 400);
        }
        if ($returnTime && $returnTime <= $time) {
            return $this->error('Thời gian quay lại phải sau thời gian ra cổng.', 400);
        }
        if ($purpose === '' || mb_strlen($purpose) > 500
            || $destination === '' || mb_strlen($destination) > 160
            || mb_strlen($vehicleInfo) > 160) {
            return $this->error('Kiểm tra lý do, nơi đến và thông tin phương tiện.', 400);
        }
        $validIds = is_array($approverIds)
            && count($approverIds) > 0
            && is_string($hrId) && ctype_digit($hrId)
            && is_string($guardId) && ctype_digit($guardId)
            && count(array_filter($approverIds, fn ($id) => ! is_string($id) || ! ctype_digit($id))) === 0;
        if (! $validIds) {
            return $this->error('Chọn ít nhất một người duyệt cùng HR và bảo vệ.', 400);
        }
        $approverIds = array_map(fn (string $id) => (string) (int) $id, $approverIds);
        $hrId = (string) (int) $hrId;
        $guardId = (string) (int) $guardId;
        if (count(array_unique($approverIds)) !== count($approverIds)
            || in_array($requesterId, [...$approverIds, $hrId, $guardId], true)
            || in_array($hrId, $approverIds, true)
            || in_array($guardId, [...$approverIds, $hrId], true)) {
            return $this->error('Chọn người duyệt, HR và bảo vệ khác nhau; không trùng người lập.', 400);
        }
        $assignedUserIds = [...$approverIds, $hrId, $guardId];
        if (! $this->activeUsersExist($assignedUserIds)) {
            return $this->error('Một trong những người được chọn không còn hoạt động.', 400);
        }
        $permitId = DB::transaction(function () use (
            $request, $approverIds, $hrId, $guardId, $direction, $date, $time,
            $returnTime, $purpose, $destination, $vehicleInfo,
        ): int {
            $permitId = DB::table('gate_permits')->insertGetId([
                'requester_id' => $this->user($request)->id,
                'approver_id' => $approverIds[0] ?? $hrId,
                'guard_id' => $guardId,
                'direction' => $direction,
                'permit_date' => $date,
                'permit_time' => $time,
                'return_time' => $returnTime ?: null,
                'purpose' => $purpose,
                'destination' => $destination,
                'vehicle_info' => $vehicleInfo,
            ]);
            $steps = [];
            foreach ($approverIds as $sequence => $approverId) {
                $steps[] = [
                    'permit_id' => $permitId,
                    'user_id' => $approverId,
                    'sequence' => $sequence + 1,
                    'is_hr' => false,
                ];
            }
            $steps[] = [
                'permit_id' => $permitId,
                'user_id' => $hrId,
                'sequence' => count($steps) + 1,
                'is_hr' => true,
            ];
            DB::table('gate_permit_approval_steps')->insert($steps);

            return $permitId;
        });
        $this->broadcastPermit($permitId);

        return response()->json($this->findPermit($permitId), 201);
    }

    public function decision(Request $request, string $id): JsonResponse
    {
        $permitId = filter_var($id, FILTER_VALIDATE_INT);
        $decision = $request->input('decision');
        $note = is_string($request->input('note')) ? trim($request->input('note')) : '';
        if (! $permitId || $permitId < 1 || ! in_array($decision, ['approve', 'reject'], true)
            || mb_strlen($note) > 500 || ($decision === 'reject' && $note === '')) {
            return $this->error('Quyết định hoặc ghi chú không hợp lệ.', 400);
        }
        $current = $this->user($request);
        $result = DB::transaction(function () use ($permitId, $current, $decision, $note): string {
            $permit = DB::table('gate_permits')
                ->where('id', $permitId)
                ->lockForUpdate()
                ->first(['status']);
            if (! $permit) {
                return 'not_found';
            }
            if ($permit->status !== 'pending_approval') {
                return 'conflict';
            }
            $step = DB::table('gate_permit_approval_steps')
                ->where('permit_id', $permitId)
                ->where('status', 'pending')
                ->orderBy('sequence')
                ->first();
            if (! $step) {
                return 'conflict';
            }
            if ((int) $step->user_id !== (int) $current->id) {
                return 'forbidden';
            }

            DB::table('gate_permit_approval_steps')
                ->where('id', $step->id)
                ->where('status', 'pending')
                ->update([
                    'status' => $decision === 'approve' ? 'approved' : 'rejected',
                    'note' => $note,
                    'decided_at' => now(),
                ]);
            if ($decision === 'reject') {
                DB::table('gate_permits')->where('id', $permitId)->update([
                    'status' => 'rejected',
                    'approver_note' => $note,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                return 'updated';
            }

            $nextStep = DB::table('gate_permit_approval_steps')
                ->where('permit_id', $permitId)
                ->where('status', 'pending')
                ->orderBy('sequence')
                ->first();
            DB::table('gate_permits')->where('id', $permitId)->update([
                'status' => $nextStep ? 'pending_approval' : 'pending_guard',
                'approver_note' => $note,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

            return 'updated';
        });
        if ($result === 'not_found') {
            return $this->error('Không tìm thấy phiếu xin phép.', 404);
        }
        if ($result === 'forbidden') {
            return $this->error('Chỉ người đang được chỉ định ở bước duyệt hiện tại mới có thể xử lý phiếu.', 403);
        }
        if ($result !== 'updated') {
            return $this->error('Phiếu đã được xử lý ở bước duyệt hiện tại.', 409);
        }
        $this->broadcastPermit($permitId);

        return response()->json($this->findPermit($permitId));
    }

    public function guard(Request $request, string $id): JsonResponse
    {
        $permitId = filter_var($id, FILTER_VALIDATE_INT);
        $decision = $request->input('decision');
        $note = is_string($request->input('note')) ? trim($request->input('note')) : '';
        if (! $permitId || $permitId < 1 || ! in_array($decision, ['confirm', 'reject'], true)
            || mb_strlen($note) > 500 || ($decision === 'reject' && $note === '')) {
            return $this->error('Xác nhận hoặc ghi chú không hợp lệ.', 400);
        }
        $permit = DB::table('gate_permits')->where('id', $permitId)->first(['guard_id', 'status']);
        if (! $permit) {
            return $this->error('Không tìm thấy phiếu xin phép.', 404);
        }
        $current = $this->user($request);
        if ((int) $permit->guard_id !== (int) $current->id) {
            return $this->error('Chỉ bảo vệ được chọn trên phiếu mới có thể xác nhận.', 403);
        }
        if ($permit->status !== 'pending_guard') {
            return $this->error('Phiếu chưa được người duyệt thông qua hoặc đã hoàn tất.', 409);
        }

        $updated = DB::table('gate_permits')
            ->where('id', $permitId)
            ->where('status', 'pending_guard')
            ->update([
                'status' => $decision === 'confirm' ? 'approved' : 'rejected',
                'guard_note' => $note,
                'guard_confirmed_at' => now(),
                'updated_at' => now(),
            ]);
        if (! $updated) {
            return $this->error('Phiếu đã được xử lý ở bước bảo vệ.', 409);
        }
        $this->broadcastPermit($permitId);

        return response()->json($this->findPermit($permitId));
    }

    private function validTime(mixed $time): bool
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    private function permitQuery()
    {
        return DB::table('gate_permits')
            ->join('users as requester', 'requester.id', '=', 'gate_permits.requester_id')
            ->join('users as approver', 'approver.id', '=', 'gate_permits.approver_id')
            ->join('users as guard', 'guard.id', '=', 'gate_permits.guard_id')
            ->select([
                'gate_permits.id',
                'gate_permits.requester_id as requesterId',
                'gate_permits.approver_id as approverId',
                'gate_permits.guard_id as guardId',
                'gate_permits.direction',
                'gate_permits.permit_date as date',
                'gate_permits.permit_time as time',
                'gate_permits.return_time as returnTime',
                'gate_permits.purpose',
                'gate_permits.destination',
                'gate_permits.vehicle_info as vehicleInfo',
                'gate_permits.status',
                'gate_permits.approver_note as approverNote',
                'gate_permits.guard_note as guardNote',
                'gate_permits.created_at as createdAt',
                'gate_permits.updated_at as updatedAt',
                'gate_permits.approved_at as approvedAt',
                'gate_permits.guard_confirmed_at as guardConfirmedAt',
                'requester.name as requesterName',
                'approver.name as approverName',
                'guard.name as guardName',
            ]);
    }

    private function findPermit(int $permitId): ?object
    {
        $permit = $this->permitQuery()->where('gate_permits.id', $permitId)->first();

        if (! $permit) {
            return null;
        }

        return $this->responsePermitUsers(collect([$permit]))->first();
    }

    private function responsePermitUsers($permits)
    {
        if ($permits->isEmpty()) {
            return $permits;
        }

        $steps = DB::table('gate_permit_approval_steps')
            ->join('users', 'users.id', '=', 'gate_permit_approval_steps.user_id')
            ->whereIn('gate_permit_approval_steps.permit_id', $permits->pluck('id'))
            ->orderBy('gate_permit_approval_steps.sequence')
            ->get([
                'gate_permit_approval_steps.permit_id as permitId',
                'gate_permit_approval_steps.user_id as userId',
                'gate_permit_approval_steps.sequence',
                'gate_permit_approval_steps.is_hr as isHr',
                'gate_permit_approval_steps.status',
                'gate_permit_approval_steps.note',
                'gate_permit_approval_steps.decided_at as decidedAt',
                'users.name',
            ])
            ->groupBy('permitId');

        return $permits->map(function ($permit) use ($steps): object {
            foreach (['requesterId', 'approverId', 'guardId'] as $field) {
                $permit->{$field} = $this->responseUserId($permit->{$field});
            }
            $permit->approvalSteps = ($steps->get($permit->id) ?? collect())
                ->map(fn ($step) => [
                    'userId' => $this->responseUserId($step->userId),
                    'name' => $step->name,
                    'sequence' => (int) $step->sequence,
                    'isHr' => (bool) $step->isHr,
                    'status' => $step->status,
                    'note' => $step->note,
                    'decidedAt' => $step->decidedAt,
                ])
                ->values()
                ->all();
            $activeStep = $permit->status === 'pending_approval'
                ? collect($permit->approvalSteps)->firstWhere('status', 'pending')
                : null;
            $permit->currentApproverId = $activeStep['userId'] ?? null;
            $permit->currentApproverIsHr = $activeStep['isHr'] ?? false;
            $permit->approverName = $permit->approvalSteps[0]['name'] ?? $permit->approverName;

            return $permit;
        });
    }

    private function broadcastPermit(int $permitId): void
    {
        $permit = DB::table('gate_permits')->where('id', $permitId)->first(['requester_id', 'approver_id', 'guard_id']);
        if ($permit) {
            $approverIds = DB::table('gate_permit_approval_steps')
                ->where('permit_id', $permitId)
                ->pluck('user_id')
                ->all();
            WorkdeskBroadcast::users(
                [$permit->requester_id, ...$approverIds, $permit->guard_id],
                'permits',
            );
        }
    }
}
