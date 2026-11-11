<?php

namespace App\Http\Controllers;

use App\Support\OrganizationAccess;
use App\Support\WorkdeskBroadcast;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/*
 * Tăng ca.
 * - Nhân viên tự khai báo tăng ca và chọn line người duyệt (duyệt lần lượt theo thứ tự).
 * - Nhân viên sửa/xóa được dòng của mình khi chưa được duyệt xong; sửa sẽ gửi duyệt lại từ đầu.
 * - Quản lý sửa/xóa được dòng của nhân viên thuộc phạm vi quản lý (OrganizationAccess::canManageUser),
 *   kể cả dòng đã duyệt; trạng thái duyệt được giữ nguyên.
 * - Ngày chốt công (overtime_settings.locked_until): mọi ngày <= ngày chốt không được thêm/sửa/xóa.
 *   Admin hệ thống, CEO hoặc người có quyền overtime_hr được đặt ngày chốt.
 */
class OvertimeController extends ApiController
{
    /** Danh sách ca tăng ca. */
    private const SHIFTS = [
        'day' => 'Ca ngày',
        'night' => 'Ca đêm',
    ];

    private const STATUS_LABELS = [
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'rejected' => 'Đã từ chối',
    ];

    private const MANAGER_LEVELS = ['ceo', 'division_head', 'department_head', 'team_lead'];

    private const MAX_APPROVERS = 10;

    /** Thông tin chung cho màn hình: ca, quyền, ngày chốt, danh sách người duyệt. */
    public function meta(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $approvers = DB::table('users')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->where('users.active', 1)
            ->where('users.id', '!=', $user->id)
            ->orderBy('users.name')
            ->get(['users.id', 'users.user_code', 'users.name', 'departments.name as department_name'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'userCode' => $row->user_code,
                'name' => $row->name,
                'departmentName' => $row->department_name,
            ]);
        $lastEntryId = DB::table('overtime_entries')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');
        $lastApproverIds = $lastEntryId
            ? DB::table('overtime_approval_steps')
                ->where('entry_id', $lastEntryId)
                ->orderBy('sequence')
                ->pluck('user_id')
                ->map(fn ($id): string => (string) $id)
                ->filter(fn (string $id): bool => $approvers->contains('id', $id))
                ->values()
            : collect();

        return response()->json([
            'shifts' => $this->shiftOptions(),
            'statuses' => self::STATUS_LABELS,
            'canViewTeam' => $this->canViewTeam($user),
            'canManageLock' => $this->hasPermission($user, 'overtime_hr'),
            'lockedUntil' => $this->lockedUntil(),
            'pendingApprovalCount' => $this->myTurnQuery($user)->count(),
            'approvers' => $approvers->values(),
            'lastApproverIds' => $lastApproverIds,
        ]);
    }

    /** Tăng ca của chính người đăng nhập trong một tháng. */
    public function mine(Request $request): JsonResponse
    {
        $month = $this->monthRange($request);
        if ($month instanceof JsonResponse) {
            return $month;
        }
        [$monthKey, $start, $end] = $month;
        $user = $this->user($request);

        $rows = $this->entriesQuery()
            ->where('overtime_entries.user_id', $user->id)
            ->whereBetween('overtime_entries.work_date', [$start, $end])
            ->orderBy('overtime_entries.work_date')
            ->orderBy('overtime_entries.shift_key')
            ->get();
        $entries = $this->formatEntries($rows, $user, []);

        return response()->json([
            'month' => $monthKey,
            'lockedUntil' => $this->lockedUntil(),
            ...$this->totals($entries),
            'entries' => $entries,
        ]);
    }

    /** Danh sách tăng ca của nhân viên trong phạm vi quản lý, theo phòng ban và tháng. */
    public function team(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $this->canViewTeam($user)) {
            return $this->error('Chỉ người quản lý được xem tăng ca của nhân viên.', 403);
        }

        $month = $this->monthRange($request);
        if ($month instanceof JsonResponse) {
            return $month;
        }
        [$monthKey, $start, $end] = $month;

        $validator = Validator::make($request->query(), [
            'departmentId' => ['nullable', 'string', 'max:36'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }
        $filters = $validator->validated();

        $isOrgAdmin = OrganizationAccess::isOrganizationAdmin($user);
        $visibleUsers = OrganizationAccess::visibleUsersQuery($user)
            ->get(['users.id', 'users.role', 'users.org_level', 'users.department_id'])
            ->unique('id');
        $visibleUserIds = $visibleUsers->pluck('id')->all();

        $departments = DB::table('departments')
            ->when(! $isOrgAdmin, fn ($query) => $query->whereIn(
                'id',
                $visibleUsers->pluck('department_id')->filter()->unique()->values()->all(),
            ))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (object $department): array => [
                'id' => (string) $department->id,
                'name' => $department->name,
            ]);

        $departmentId = $filters['departmentId'] ?? '';
        if ($departmentId !== '' && ! $departments->contains('id', $departmentId)) {
            return $this->error('Bạn không có quyền xem phòng ban này.', 403);
        }

        $query = $this->entriesQuery()->whereBetween('overtime_entries.work_date', [$start, $end]);
        if (! $isOrgAdmin) {
            $query->whereIn('overtime_entries.user_id', $visibleUserIds);
        }
        if ($departmentId !== '') {
            $query->where('users.department_id', $departmentId);
        }
        if (! empty($filters['status'])) {
            $query->where('overtime_entries.status', $filters['status']);
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%' . addcslashes($search, '\\%_') . '%';
            $query->where(function ($builder) use ($pattern): void {
                $builder->where('users.user_code', 'like', $pattern)
                    ->orWhere('users.name', 'like', $pattern);
            });
        }

        $rows = $query
            ->orderBy('departments.name')
            ->orderBy('users.name')
            ->orderBy('overtime_entries.work_date')
            ->orderBy('overtime_entries.shift_key')
            ->get();
        $entries = $this->formatEntries($rows, $user, $this->manageableUserIds($user, $visibleUsers));

        $summary = $entries
            ->groupBy('userId')
            ->map(fn (Collection $items): array => [
                'userId' => $items[0]['userId'],
                'userCode' => $items[0]['userCode'],
                'userName' => $items[0]['userName'],
                'departmentName' => $items[0]['departmentName'],
                'days' => $items->pluck('date')->unique()->count(),
                ...$this->totals($items),
            ])
            ->values();

        return response()->json([
            'month' => $monthKey,
            'departmentId' => $departmentId,
            'departments' => $departments->values(),
            'lockedUntil' => $this->lockedUntil(),
            ...$this->totals($entries),
            'summary' => $summary,
            'entries' => $entries,
        ]);
    }

    /**
     * Tăng ca mà người đăng nhập nằm trong line duyệt.
     * mode=pending: các dòng đang đến lượt mình duyệt (mọi tháng).
     * mode=history: mọi dòng có mình trong line duyệt, theo tháng.
     */
    public function approvals(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $mode = $request->query('mode', 'pending');
        if (! in_array($mode, ['pending', 'history'], true)) {
            return $this->error('Chế độ xem không hợp lệ.', 400);
        }

        $query = $this->entriesQuery();
        $monthKey = null;
        if ($mode === 'pending') {
            $query->whereIn('overtime_entries.id', $this->myTurnQuery($user)->select('overtime_approval_steps.entry_id'));
        } else {
            $month = $this->monthRange($request);
            if ($month instanceof JsonResponse) {
                return $month;
            }
            [$monthKey, $start, $end] = $month;
            $query->whereBetween('overtime_entries.work_date', [$start, $end])
                ->whereExists(function ($subquery) use ($user): void {
                    $subquery->selectRaw('1')
                        ->from('overtime_approval_steps')
                        ->whereColumn('overtime_approval_steps.entry_id', 'overtime_entries.id')
                        ->where('overtime_approval_steps.user_id', $user->id);
                });
        }

        $rows = $query
            ->orderBy('overtime_entries.work_date')
            ->orderBy('users.name')
            ->orderBy('overtime_entries.shift_key')
            ->get();
        $entries = $this->formatEntries($rows, $user, $this->manageableUserIds($user));

        return response()->json([
            'mode' => $mode,
            'month' => $monthKey,
            ...$this->totals($entries),
            'entries' => $entries,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->validatedEntry($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $approverIds = $this->validatedApprovers($request, $user->id);
        if ($approverIds instanceof JsonResponse) {
            return $approverIds;
        }
        if ($this->isLocked($data['date'])) {
            return $this->lockedError();
        }
        $conflict = $this->conflict($user->id, $data, null);
        if ($conflict) {
            return $conflict;
        }

        $id = DB::transaction(function () use ($user, $data, $approverIds): int {
            $now = now();
            $id = DB::table('overtime_entries')->insertGetId([
                'user_id' => $user->id,
                'work_date' => $data['date'],
                'shift_key' => $data['shift'],
                'hours' => $data['hours'],
                'note' => $data['note'],
                'status' => 'pending',
                'updated_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceSteps($id, $approverIds);

            return $id;
        });
        $this->broadcastEntry($id);

        return response()->json(['entry' => $this->findEntry($id, $user)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $access = $this->editableEntry($user, $id);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$entry, $isOwner] = $access;

        $data = $this->validatedEntry($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $approverIds = null;
        if ($isOwner) {
            $approverIds = $this->validatedApprovers($request, $user->id);
            if ($approverIds instanceof JsonResponse) {
                return $approverIds;
            }
        }
        if ($this->isLocked($data['date'])) {
            return $this->lockedError();
        }
        $conflict = $this->conflict($entry->user_id, $data, (int) $entry->id);
        if ($conflict) {
            return $conflict;
        }

        $oldApproverIds = $this->stepUserIds((int) $entry->id);
        DB::transaction(function () use ($entry, $data, $user, $approverIds): void {
            $changes = [
                'work_date' => $data['date'],
                'shift_key' => $data['shift'],
                'hours' => $data['hours'],
                'note' => $data['note'],
                'updated_by' => $user->id,
                'updated_at' => now(),
            ];
            if ($approverIds !== null) {
                // Nhân viên sửa dòng của mình: gửi duyệt lại từ đầu theo line mới.
                $changes['status'] = 'pending';
                $this->replaceSteps((int) $entry->id, $approverIds);
            }
            DB::table('overtime_entries')->where('id', $entry->id)->update($changes);
        });
        $this->broadcastEntry((int) $entry->id, $oldApproverIds);

        return response()->json(['entry' => $this->findEntry((int) $entry->id, $user)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $access = $this->editableEntry($user, $id);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$entry] = $access;

        $notify = [$entry->user_id, ...$this->stepUserIds((int) $entry->id)];
        DB::table('overtime_entries')->where('id', $entry->id)->delete();
        WorkdeskBroadcast::users($notify, 'overtime');

        return response()->json(['ok' => true]);
    }

    /** Duyệt hoặc từ chối một dòng tăng ca (người đang đến lượt trong line duyệt). */
    public function decision(Request $request, string $id): JsonResponse
    {
        $input = $this->validatedDecision($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        if (! ctype_digit($id)) {
            return $this->error('Không tìm thấy dữ liệu tăng ca.', 404);
        }
        $result = $this->decide($this->user($request), (int) $id, ...$input);

        return match ($result) {
            'updated' => response()->json(['entry' => $this->findEntry((int) $id, $this->user($request))]),
            'not_found' => $this->error('Không tìm thấy dữ liệu tăng ca.', 404),
            'forbidden' => $this->error('Chỉ người đang đến lượt trong line duyệt mới được xử lý dòng này.', 403),
            default => $this->error('Dòng tăng ca này đã được xử lý.', 409),
        };
    }

    /** Duyệt hoặc từ chối nhiều dòng một lần. */
    public function bulkDecision(Request $request): JsonResponse
    {
        $input = $this->validatedDecision($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        $ids = $request->input('ids');
        if (! is_array($ids) || count($ids) === 0 || count($ids) > 500
            || count(array_filter($ids, fn ($id): bool => ! (is_int($id) || is_string($id) && ctype_digit($id)))) > 0) {
            return $this->error('Chọn ít nhất một dòng tăng ca để xử lý.', 400);
        }

        $user = $this->user($request);
        $updated = 0;
        foreach (array_unique(array_map('intval', $ids)) as $entryId) {
            if ($this->decide($user, $entryId, ...$input) === 'updated') {
                $updated++;
            }
        }

        return response()->json(['updated' => $updated, 'skipped' => count($ids) - $updated]);
    }

    /** Đặt hoặc bỏ ngày chốt công. */
    public function updateLock(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $this->hasPermission($user, 'overtime_hr')) {
            return $this->error('Bạn không có quyền chốt công tăng ca.', 403);
        }
        $lockedUntil = $request->input('lockedUntil');
        if ($lockedUntil !== null && (! is_string($lockedUntil)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockedUntil)
            || ! checkdate((int) substr($lockedUntil, 5, 2), (int) substr($lockedUntil, 8, 2), (int) substr($lockedUntil, 0, 4)))) {
            return $this->error('Ngày chốt công không hợp lệ.', 400);
        }

        DB::table('overtime_settings')->updateOrInsert(
            ['setting_key' => 'locked_until'],
            ['setting_value' => $lockedUntil, 'updated_by' => $user->id, 'updated_at' => now()],
        );

        return response()->json(['lockedUntil' => $lockedUntil]);
    }

    // ---------------------------------------------------------------- helpers

    private function canViewTeam(object $user): bool
    {
        return OrganizationAccess::isOrganizationAdmin($user)
            || in_array($user->org_level ?? 'employee', self::MANAGER_LEVELS, true);
    }

    /**
     * Id các nhân viên mà người đăng nhập được sửa/xóa tăng ca (cấp dưới trong phạm vi quản lý).
     * Kết quả tương đương OrganizationAccess::canManageUser nhưng tính một lần cho cả danh sách.
     */
    private function manageableUserIds(object $user, ?Collection $visibleUsers = null): array
    {
        if (! $this->canViewTeam($user)) {
            return [];
        }
        $visibleUsers ??= OrganizationAccess::visibleUsersQuery($user)
            ->get(['users.id', 'users.role', 'users.org_level'])
            ->unique('id');
        $isSystemAdmin = OrganizationAccess::isSystemAdmin($user);
        $rank = OrganizationAccess::rank($user);

        return $visibleUsers
            ->filter(fn (object $target): bool => (int) $target->id !== (int) $user->id
                && ($isSystemAdmin || $rank > OrganizationAccess::rank($target)))
            ->map(fn (object $target): int => (int) $target->id)
            ->values()
            ->all();
    }

    /** Các bước duyệt đang đến lượt người dùng (bước pending có thứ tự nhỏ nhất). */
    private function myTurnQuery(object $user)
    {
        return DB::table('overtime_approval_steps')
            ->join('overtime_entries', 'overtime_entries.id', '=', 'overtime_approval_steps.entry_id')
            ->where('overtime_approval_steps.user_id', $user->id)
            ->where('overtime_approval_steps.status', 'pending')
            ->where('overtime_entries.status', 'pending')
            ->whereNotExists(function ($subquery): void {
                $subquery->selectRaw('1')
                    ->from('overtime_approval_steps as earlier')
                    ->whereColumn('earlier.entry_id', 'overtime_approval_steps.entry_id')
                    ->where('earlier.status', 'pending')
                    ->whereColumn('earlier.sequence', '<', 'overtime_approval_steps.sequence');
            });
    }

    /** @return array{0:object,1:bool}|JsonResponse dòng tăng ca và việc người dùng có phải chủ dòng không */
    private function editableEntry(object $user, string $id): array|JsonResponse
    {
        $entry = ctype_digit($id) ? DB::table('overtime_entries')->where('id', (int) $id)->first() : null;
        if (! $entry) {
            return $this->error('Không tìm thấy dữ liệu tăng ca.', 404);
        }
        if ($this->isLocked(substr((string) $entry->work_date, 0, 10))) {
            return $this->lockedError();
        }

        $isOwner = (int) $entry->user_id === (int) $user->id;
        if ($isOwner) {
            if ($entry->status === 'approved') {
                return $this->error('Tăng ca đã được duyệt, chỉ quản lý mới được sửa hoặc xóa.', 409);
            }

            return [$entry, true];
        }

        $target = DB::table('users')->where('id', $entry->user_id)->first();
        if (! $target || ! OrganizationAccess::canManageUser($user, $target)) {
            return $this->error('Bạn chỉ được sửa hoặc xóa tăng ca của mình hoặc của nhân viên mình quản lý.', 403);
        }

        return [$entry, false];
    }

    private function decide(object $user, int $entryId, string $decision, string $note): string
    {
        $result = DB::transaction(function () use ($user, $entryId, $decision, $note): string {
            $entry = DB::table('overtime_entries')->where('id', $entryId)->lockForUpdate()->first(['id', 'status']);
            if (! $entry) {
                return 'not_found';
            }
            if ($entry->status !== 'pending') {
                return 'conflict';
            }
            $step = DB::table('overtime_approval_steps')
                ->where('entry_id', $entryId)
                ->where('status', 'pending')
                ->orderBy('sequence')
                ->first();
            if (! $step) {
                return 'conflict';
            }
            if ((int) $step->user_id !== (int) $user->id) {
                return 'forbidden';
            }

            DB::table('overtime_approval_steps')->where('id', $step->id)->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'note' => $note,
                'decided_at' => now(),
            ]);
            $hasNext = $decision === 'approve' && DB::table('overtime_approval_steps')
                ->where('entry_id', $entryId)
                ->where('status', 'pending')
                ->exists();
            DB::table('overtime_entries')->where('id', $entryId)->update([
                'status' => $decision === 'reject' ? 'rejected' : ($hasNext ? 'pending' : 'approved'),
                'updated_at' => now(),
            ]);

            return 'updated';
        });
        if ($result === 'updated') {
            $this->broadcastEntry($entryId);
        }

        return $result;
    }

    /** @return array{0:string,1:string}|JsonResponse */
    private function validatedDecision(Request $request): array|JsonResponse
    {
        $decision = $request->input('decision');
        $note = is_string($request->input('note')) ? trim($request->input('note')) : '';
        if (! in_array($decision, ['approve', 'reject'], true) || mb_strlen($note) > 500) {
            return $this->error('Quyết định hoặc ghi chú không hợp lệ.', 400);
        }
        if ($decision === 'reject' && $note === '') {
            return $this->error('Vui lòng nhập lý do từ chối.', 400);
        }

        return [$decision, $note];
    }

    private function replaceSteps(int $entryId, array $approverIds): void
    {
        DB::table('overtime_approval_steps')->where('entry_id', $entryId)->delete();
        DB::table('overtime_approval_steps')->insert(array_map(
            fn (int $userId, int $index): array => [
                'entry_id' => $entryId,
                'user_id' => $userId,
                'sequence' => $index + 1,
                'status' => 'pending',
            ],
            $approverIds,
            array_keys($approverIds),
        ));
    }

    private function stepUserIds(int $entryId): array
    {
        return DB::table('overtime_approval_steps')->where('entry_id', $entryId)->pluck('user_id')->all();
    }

    private function broadcastEntry(int $entryId, array $extraUserIds = []): void
    {
        $ownerId = DB::table('overtime_entries')->where('id', $entryId)->value('user_id');
        WorkdeskBroadcast::users([$ownerId, ...$this->stepUserIds($entryId), ...$extraUserIds], 'overtime');
    }

    private function lockedUntil(): ?string
    {
        $value = DB::table('overtime_settings')->where('setting_key', 'locked_until')->value('setting_value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isLocked(string $date): bool
    {
        $lockedUntil = $this->lockedUntil();

        return $lockedUntil !== null && $date <= $lockedUntil;
    }

    private function lockedError(): JsonResponse
    {
        $lockedUntil = (new DateTimeImmutable((string) $this->lockedUntil()))->format('d/m/Y');

        return $this->error("Đã chốt công đến hết ngày $lockedUntil, không thể thêm, sửa hoặc xóa tăng ca trong khoảng này.", 423);
    }

    /** @return array{0:string,1:string,2:string}|JsonResponse */
    private function monthRange(Request $request): array|JsonResponse
    {
        $month = $request->query('month');
        if ($month === null || $month === '') {
            $month = now('Asia/Ho_Chi_Minh')->format('Y-m');
        }
        if (! is_string($month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $this->error('Tháng phải có định dạng YYYY-MM.', 400);
        }
        $start = new DateTimeImmutable($month . '-01');

        return [$month, $start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }

    private function validatedEntry(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift' => ['required', 'string', 'in:' . implode(',', array_keys(self::SHIFTS))],
            'date' => ['required', 'date_format:Y-m-d'],
            'hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'shift.required' => 'Vui lòng chọn ca.',
            'shift.in' => 'Ca không hợp lệ.',
            'date.required' => 'Vui lòng chọn ngày.',
            'date.date_format' => 'Ngày không hợp lệ.',
            'hours.required' => 'Vui lòng nhập số giờ.',
            'hours.numeric' => 'Số giờ phải là số.',
            'hours.min' => 'Số giờ tối thiểu là 0,5.',
            'hours.max' => 'Số giờ tối đa là 24.',
            'note.max' => 'Ghi chú tối đa 500 ký tự.',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }
        $data = $validator->validated();
        $hours = (float) $data['hours'];
        if (abs($hours * 2 - round($hours * 2)) > 1e-9) {
            return $this->error('Số giờ phải là bội số của 0,5 (ví dụ 1; 1,5; 2).', 400);
        }

        return [
            'shift' => $data['shift'],
            'date' => $data['date'],
            'hours' => $hours,
            'note' => trim((string) ($data['note'] ?? '')),
        ];
    }

    /** @return int[]|JsonResponse */
    private function validatedApprovers(Request $request, int|string $ownerId): array|JsonResponse
    {
        $ids = $request->input('approverIds');
        if (! is_array($ids) || count($ids) === 0) {
            return $this->error('Vui lòng chọn line duyệt (ít nhất một người duyệt).', 400);
        }
        if (count($ids) > self::MAX_APPROVERS) {
            return $this->error('Line duyệt tối đa ' . self::MAX_APPROVERS . ' người.', 400);
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (! (is_int($id) || is_string($id) && ctype_digit($id))) {
                return $this->error('Người duyệt không hợp lệ.', 400);
            }
            $normalized[] = (int) $id;
        }
        if (count(array_unique($normalized)) !== count($normalized)) {
            return $this->error('Mỗi người chỉ xuất hiện một lần trong line duyệt.', 400);
        }
        if (in_array((int) $ownerId, $normalized, true)) {
            return $this->error('Không được tự chọn mình làm người duyệt.', 400);
        }
        if (! $this->activeUsersExist($normalized)) {
            return $this->error('Một người trong line duyệt không còn hoạt động.', 400);
        }

        return $normalized;
    }

    private function conflict(int|string $userId, array $data, ?int $exceptId): ?JsonResponse
    {
        $sameDay = DB::table('overtime_entries')
            ->where('user_id', $userId)
            ->where('work_date', $data['date'])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId));

        if ((clone $sameDay)->where('shift_key', $data['shift'])->exists()) {
            return $this->error('Đã có tăng ca ' . self::SHIFTS[$data['shift']] . ' trong ngày này. Hãy sửa dòng đã có.', 409);
        }
        if ((float) $sameDay->sum('hours') + $data['hours'] > 24) {
            return $this->error('Tổng số giờ tăng ca trong một ngày không được vượt quá 24 giờ.', 400);
        }

        return null;
    }

    private function entriesQuery()
    {
        return DB::table('overtime_entries')
            ->join('users', 'users.id', '=', 'overtime_entries.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->select([
                'overtime_entries.id',
                'overtime_entries.user_id',
                'overtime_entries.work_date',
                'overtime_entries.shift_key',
                'overtime_entries.hours',
                'overtime_entries.note',
                'overtime_entries.status',
                'overtime_entries.updated_at',
                'users.user_code',
                'users.name as user_name',
                'departments.name as department_name',
            ]);
    }

    private function findEntry(int $id, object $viewer): ?array
    {
        $rows = $this->entriesQuery()->where('overtime_entries.id', $id)->get();

        return $this->formatEntries($rows, $viewer, $this->manageableUserIds($viewer))->first();
    }

    private function formatEntries(Collection $rows, object $viewer, array $manageableUserIds): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        $steps = DB::table('overtime_approval_steps')
            ->join('users', 'users.id', '=', 'overtime_approval_steps.user_id')
            ->whereIn('overtime_approval_steps.entry_id', $rows->pluck('id'))
            ->orderBy('overtime_approval_steps.sequence')
            ->get([
                'overtime_approval_steps.entry_id',
                'overtime_approval_steps.user_id',
                'overtime_approval_steps.sequence',
                'overtime_approval_steps.status',
                'overtime_approval_steps.note',
                'overtime_approval_steps.decided_at',
                'users.name',
                'users.user_code',
            ])
            ->groupBy('entry_id');
        $lockedUntil = $this->lockedUntil();
        $manageable = array_flip($manageableUserIds);

        return $rows->map(function (object $row) use ($steps, $viewer, $lockedUntil, $manageable): array {
            $date = substr((string) $row->work_date, 0, 10);
            $approvalSteps = ($steps->get($row->id) ?? collect())->map(fn (object $step): array => [
                'userId' => (string) $step->user_id,
                'name' => $step->name,
                'userCode' => $step->user_code,
                'sequence' => (int) $step->sequence,
                'status' => $step->status,
                'note' => $step->note,
                'decidedAt' => $step->decided_at,
            ])->values();
            $currentStep = $row->status === 'pending' ? $approvalSteps->firstWhere('status', 'pending') : null;
            $isOwner = (int) $row->user_id === (int) $viewer->id;
            $locked = $lockedUntil !== null && $date <= $lockedUntil;
            $canModify = ! $locked && ($isOwner
                ? $row->status !== 'approved'
                : isset($manageable[(int) $row->user_id]));

            return [
                'id' => (string) $row->id,
                'userId' => (string) $row->user_id,
                'userCode' => $row->user_code,
                'userName' => $row->user_name,
                'departmentName' => $row->department_name,
                'date' => $date,
                'shift' => $row->shift_key,
                'shiftLabel' => self::SHIFTS[$row->shift_key] ?? $row->shift_key,
                'hours' => (float) $row->hours,
                'note' => $row->note,
                'status' => $row->status,
                'statusLabel' => self::STATUS_LABELS[$row->status] ?? $row->status,
                'approvalSteps' => $approvalSteps,
                'currentApproverId' => $currentStep['userId'] ?? null,
                'currentApproverName' => $currentStep['name'] ?? null,
                'isOwner' => $isOwner,
                'locked' => $locked,
                'canEdit' => $canModify,
                'canDelete' => $canModify,
                'canDecide' => $currentStep !== null && (int) $currentStep['userId'] === (int) $viewer->id,
                'updatedAt' => $row->updated_at,
            ];
        })->values();
    }

    private function totals(Collection $entries): array
    {
        return [
            'totalHours' => round((float) $entries->sum('hours'), 1),
            'approvedHours' => round((float) $entries->where('status', 'approved')->sum('hours'), 1),
            'pendingHours' => round((float) $entries->where('status', 'pending')->sum('hours'), 1),
        ];
    }

    private function shiftOptions(): array
    {
        return array_map(
            fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
            array_keys(self::SHIFTS),
            self::SHIFTS,
        );
    }
}
