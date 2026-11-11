<?php

namespace App\Http\Controllers;

use App\Support\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MealReportsController extends ApiController
{
    private const SHIFTS = ['morning', 'noon', 'afternoon', 'night'];

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'shift' => ['nullable', 'in:morning,noon,afternoon,night'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $filters = $validator->validated();
        $reportDate = $filters['date'] ?? Carbon::now('Asia/Ho_Chi_Minh')->toDateString();
        $shift = $filters['shift'] ?? 'morning';
        $user = $this->user($request);
        $isAdmin = $user->role === 'admin';
        $isHr = $this->isMealHr($user);
        $cutoffs = DB::table('meal_report_cutoffs')->pluck('cutoff_time', 'shift_key');
        $cutoffTime = $cutoffs[$shift] ?? '23:59:00';
        $cutoffAt = Carbon::parse("$reportDate $cutoffTime", 'Asia/Ho_Chi_Minh');
        $isClosed = Carbon::now('Asia/Ho_Chi_Minh')->greaterThanOrEqualTo($cutoffAt);

        $manageableDepartments = $this->manageableDepartments($user);
        $canManageReporters = $isAdmin || $user->org_level === 'ceo'
            || in_array($user->org_level, ['division_head', 'department_head'], true);

        $departments = DB::table('departments')
            ->orderBy('name')
            ->get(['id', 'name']);

        $departmentIds = $departments->pluck('id')->all();
        $reports = $departmentIds
            ? DB::table('meal_reports')
                ->whereDate('report_date', $reportDate)
                ->where('shift_key', $shift)
                ->whereIn('department_id', $departmentIds)
                ->get()
                ->keyBy(fn (object $row): string => $row->department_id . ':' . $row->workshop)
            : collect();
        $koreanMeals = DB::table('meal_korean_reports')
            ->whereDate('report_date', $reportDate)
            ->where('shift_key', $shift)
            ->get()
            ->keyBy('workshop');

        $canEditVietnamese = ! $isClosed
            && ($isAdmin || $manageableDepartments->isNotEmpty()
                || DB::table('meal_department_reporters')
                    ->where('user_id', $user->id)
                    ->where('shift_key', $shift)
                    ->exists());
        $canEditKorean = ! $isClosed && ($isAdmin || $isHr);

        $departmentData = $departments->map(function (object $department) use (
            $reports,
            $shift,
            $user,
            $isAdmin,
            $isHr,
            $isClosed,
            $manageableDepartments,
        ): array {
            $reporterId = $this->canManageDepartment($user, $department->id)
                ? ($this->reporterUserIds($department->id, $shift)[0] ?? null)
                : null;
            $canManage = ! $isClosed && ($isAdmin || $manageableDepartments->contains(
                fn (object $managed): bool => (string) $managed->id === (string) $department->id,
            ));
            $canReport = $canManage || ! $isClosed
                && in_array((string) $user->id, $this->reporterUserIds($department->id, $shift), true);
            $workshops = [];
            foreach ([1, 2] as $workshop) {
                $report = $reports->get($department->id . ':' . $workshop);
                $workshops[] = [
                    'workshop' => $workshop,
                    'vietnameseQty' => $report?->vietnamese_qty,
                    'canEditVietnamese' => $canReport && ! $isClosed,
                    'reporterId' => $reporterId,
                ];
            }

            return [
                'id' => (string) $department->id,
                'name' => $department->name,
                'workshops' => $workshops,
                'canManageReporters' => $this->canManageDepartment($user, $department->id),
            ];
        });

        $reporterOptions = collect();
        if ($canManageReporters) {
            $manageableIds = $manageableDepartments->pluck('id')->all();
            $reporterOptions = DB::table('users')
                ->whereIn('department_id', $manageableIds)
                ->where('active', 1)
                ->where('role', '!=', 'admin')
                ->whereNotIn('org_level', ['ceo', 'division_head', 'department_head'])
                ->orderBy('name')
                ->get(['id', 'department_id as departmentId', 'name', 'user_code'])
                ->map(fn (object $person): array => [
                    'id' => (string) $person->id,
                    'departmentId' => (string) $person->departmentId,
                    'name' => $person->name,
                    'userCode' => $person->user_code,
                ]);
        }

        return response()->json([
            'date' => $reportDate,
            'shift' => $shift,
            'cutoffTime' => substr((string) $cutoffTime, 0, 5),
            'isClosed' => $isClosed,
            'isAdmin' => $isAdmin,
            'isHr' => $isHr,
            'canEditVietnamese' => $canEditVietnamese,
            'canEditKorean' => $canEditKorean,
            'koreanMeals' => collect([1, 2])->map(fn (int $workshop): array => [
                'workshop' => $workshop,
                'koreanQty' => $koreanMeals->get($workshop)?->korean_qty,
                'canEdit' => $canEditKorean,
            ]),
            'cutoffs' => collect(self::SHIFTS)->map(fn (string $key): array => [
                'shift' => $key,
                'cutoffTime' => substr((string) ($cutoffs[$key] ?? '23:59:00'), 0, 5),
            ]),
            'departments' => $departmentData,
            'reporterOptions' => $reporterOptions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'shift' => ['required', 'in:morning,noon,afternoon,night'],
            'departmentId' => [
                'required_if:mealType,vietnamese',
                'prohibited_if:mealType,korean',
                'string',
                'exists:departments,id',
            ],
            'workshop' => ['required', 'integer', 'in:1,2'],
            'mealType' => ['required', 'in:vietnamese,korean'],
            'quantity' => ['required', 'integer', 'min:0', 'max:99999'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $data = $validator->validated();
        $user = $this->user($request);
        $cutoff = DB::table('meal_report_cutoffs')->where('shift_key', $data['shift'])->value('cutoff_time') ?? '23:59:00';
        if (Carbon::now('Asia/Ho_Chi_Minh')->greaterThanOrEqualTo(
            Carbon::parse($data['date'] . ' ' . $cutoff, 'Asia/Ho_Chi_Minh'),
        )) {
            return $this->error('Ca này đã qua thời hạn chốt, không thể nhập hoặc sửa.', 409);
        }

        if ($data['mealType'] === 'vietnamese') {
            $assignedReporter = (string) $user->department_id === (string) $data['departmentId']
                && (bool) ($user->active ?? false)
                && $user->role !== 'admin'
                && ! in_array($user->org_level, ['ceo', 'division_head', 'department_head'], true)
                && in_array(
                    (string) $user->id,
                    $this->reporterUserIds($data['departmentId'], $data['shift']),
                    true,
                );
            $authorized = $user->role === 'admin'
                || $this->canManageDepartment($user, $data['departmentId'])
                || $assignedReporter;
        } else {
            $authorized = $user->role === 'admin' || $this->isMealHr($user);
        }
        if (! $authorized) {
            return $this->error('Bạn không có quyền nhập số lượng suất ăn này.', 403);
        }

        if ($data['mealType'] === 'korean') {
            $now = now();
            DB::table('meal_korean_reports')->upsert(
                [[
                    'report_date' => $data['date'],
                    'shift_key' => $data['shift'],
                    'workshop' => $data['workshop'],
                    'korean_qty' => $data['quantity'],
                    'submitted_by' => $user->id,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]],
                ['report_date', 'shift_key', 'workshop'],
                ['korean_qty', 'submitted_by', 'updated_at'],
            );

            return response()->json(['ok' => true]);
        }

        $quantityColumn = $data['mealType'] === 'vietnamese' ? 'vietnamese_qty' : 'korean_qty';
        $submitterColumn = $data['mealType'] === 'vietnamese' ? 'vietnamese_submitted_by' : 'korean_submitted_by';
        $now = now();
        $row = [
            'report_date' => $data['date'],
            'shift_key' => $data['shift'],
            'department_id' => $data['departmentId'],
            'workshop' => $data['workshop'],
            $quantityColumn => $data['quantity'],
            $submitterColumn => $user->id,
            'updated_at' => $now,
            'created_at' => $now,
        ];
        DB::table('meal_reports')->upsert(
            [$row],
            [
                'report_date',
                'shift_key',
                'department_id',
                'workshop',
            ],
            [$quantityColumn, $submitterColumn, 'updated_at'],
        );

        return response()->json(['ok' => true]);
    }

    public function updateCutoffs(Request $request): JsonResponse
    {
        if ($this->user($request)->role !== 'admin') {
            return $this->error('Chỉ admin hệ thống được thay đổi giờ chốt báo cơm.', 403);
        }

        $cutoffs = $request->input('cutoffs');
        if (! is_array($cutoffs) || count($cutoffs) !== count(self::SHIFTS)
            || array_diff(array_keys($cutoffs), self::SHIFTS)) {
            return $this->error('Cần cấu hình giờ chốt cho đủ bốn ca.', 400);
        }

        foreach (self::SHIFTS as $shift) {
            if (! is_string($cutoffs[$shift] ?? null)
                || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoffs[$shift])) {
                return $this->error('Giờ chốt phải có định dạng HH:mm hợp lệ.', 400);
            }
        }

        DB::transaction(function () use ($cutoffs): void {
            foreach ($cutoffs as $shift => $time) {
                DB::table('meal_report_cutoffs')->updateOrInsert(
                    ['shift_key' => $shift],
                    ['cutoff_time' => $time . ':00', 'updated_at' => now()],
                );
            }
        });

        return response()->json(['ok' => true]);
    }

    public function updateReporters(Request $request, string $departmentId): JsonResponse
    {
        $user = $this->user($request);
        $shift = $request->input('shift');
        $userId = $request->input('userId');
        if (! in_array($shift, self::SHIFTS, true)
            || $userId !== null && ! is_int($userId) && ! is_string($userId)) {
            return $this->error('Người được phân công trưởng ca không hợp lệ.', 400);
        }
        if (! $this->canManageDepartment($user, $departmentId)) {
            return $this->error('Bạn không có quyền phân công trưởng ca cho phòng ban này.', 403);
        }
        if ($userId !== null) {
            $eligible = DB::table('users')
                ->where('id', $userId)
                ->where('department_id', $departmentId)
                ->where('active', 1)
                ->where('role', '!=', 'admin')
                ->whereNotIn('org_level', ['ceo', 'division_head', 'department_head'])
                ->exists();
            if (! $eligible) {
                return $this->error('Chỉ được phân công một nhân viên/trưởng nhóm đang hoạt động trong phòng ban.', 400);
            }
        }

        DB::transaction(function () use ($departmentId, $shift, $userId, $user): void {
            DB::table('meal_department_reporters')
                ->where('department_id', $departmentId)
                ->where('shift_key', $shift)
                ->delete();
            if ($userId !== null) {
                DB::table('meal_department_reporters')->insert([
                    'department_id' => $departmentId,
                    'shift_key' => $shift,
                    'user_id' => $userId,
                    'assigned_by' => $user->id,
                ]);
            }
        });

        return response()->json(['ok' => true]);
    }

    private function isMealHr(object $user): bool
    {
        return DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('permission', 'meal_hr')
            ->exists();
    }

    private function manageableDepartments(object $user)
    {
        return DB::table('departments')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (object $department): bool => $this->canManageDepartment($user, $department->id))
            ->values();
    }

    private function reporterUserIds(string $departmentId, string $shift): array
    {
        return DB::table('meal_department_reporters')
            ->join('users', 'users.id', '=', 'meal_department_reporters.user_id')
            ->where('meal_department_reporters.department_id', $departmentId)
            ->where('meal_department_reporters.shift_key', $shift)
            ->where('users.department_id', $departmentId)
            ->where('users.active', 1)
            ->where('users.role', '!=', 'admin')
            ->whereNotIn('users.org_level', ['ceo', 'division_head', 'department_head'])
            ->pluck('meal_department_reporters.user_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    private function canManageDepartment(object $user, string $departmentId): bool
    {
        return $user->role === 'admin'
            || $user->org_level === 'ceo'
            || in_array($user->org_level ?? '', ['division_head', 'department_head'], true)
                && OrganizationAccess::canManageDepartment($user, $departmentId);
    }
}
