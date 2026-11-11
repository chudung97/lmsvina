<?php

namespace App\Http\Controllers;

use App\Support\OrganizationAccess;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'perPage' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $filters = $validator->validated();
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        if ($from !== null && $to !== null && $from > $to) {
            return $this->error('Ngày bắt đầu không được sau ngày kết thúc.', 400);
        }

        $query = DB::table('cham_cong')
            ->leftJoin('users', 'users.timekeeping_code', '=', 'cham_cong.user_code');
        $viewer = $this->user($request);
        if (! OrganizationAccess::isOrganizationAdmin($viewer)) {
            $userCodes = OrganizationAccess::visibleUsersQuery($viewer)
                ->whereNotNull('users.timekeeping_code')
                ->pluck('users.timekeeping_code')
                ->unique()
                ->values();
            $query->whereIn('cham_cong.user_code', $userCodes);
        }

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%' . addcslashes($search, '\\%_') . '%';
            $query->where(function ($builder) use ($pattern): void {
                $builder->where('cham_cong.user_code', 'like', $pattern)
                    ->orWhere('users.user_code', 'like', $pattern)
                    ->orWhere('users.name', 'like', $pattern);
            });
        }
        if ($from !== null) {
            $query->where('cham_cong.check_time', '>=', $from . ' 00:00:00');
        }
        if ($to !== null) {
            $toExclusive = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
            $query->where('cham_cong.check_time', '<', $toExclusive);
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['perPage'] ?? 25);
        $records = $query
            ->orderByDesc('cham_cong.check_time')
            ->orderByDesc('cham_cong.id')
            ->paginate($perPage, [
                'cham_cong.id',
                'users.user_code as userCode',
                'cham_cong.user_code as timekeepingCode',
                'users.name as userName',
                'cham_cong.check_time as checkTime',
            ], 'page', $page);

        return response()->json([
            'data' => $records->items(),
            'page' => $records->currentPage(),
            'perPage' => $records->perPage(),
            'total' => $records->total(),
            'totalPages' => $records->lastPage(),
        ]);
    }
}
