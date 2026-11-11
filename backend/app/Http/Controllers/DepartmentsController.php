<?php

namespace App\Http\Controllers;

use App\Support\WorkdeskBroadcast;
use App\Support\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DepartmentsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $query = DB::table('departments')
            ->leftJoin('users as managers', 'managers.id', '=', 'departments.manager_user_id')
            ->leftJoin('divisions', 'divisions.id', '=', 'departments.division_id');
        if (! OrganizationAccess::isOrganizationAdmin($user)) {
            if ($user->org_level === 'division_head') {
                $query->whereIn('departments.division_id', DB::table('divisions')->where('manager_user_id', $user->id)->select('id'));
            } elseif ($user->org_level === 'department_head') {
                $query->where('departments.manager_user_id', $user->id);
            } elseif ($user->org_level === 'team_lead') {
                $query->whereIn('departments.id', DB::table('teams')->where('manager_user_id', $user->id)->select('department_id'));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $departments = $query
            ->orderBy('departments.name')
            ->get([
                'departments.id',
                'departments.name',
                'departments.division_id as divisionId',
                'divisions.name as divisionName',
                'departments.manager_user_id as managerUserId',
                'managers.name as managerName',
            ])
            ->map(function ($department): object {
                $department->memberCount = DB::table('users')->where('department_id', $department->id)->count();
                $department->managerUserId = $this->responseUserId($department->managerUserId);

                return $department;
            });

        return response()->json($departments);
    }

    public function store(Request $request): JsonResponse
    {
        $current = $this->user($request);
        $name = is_string($request->input('name')) ? trim($request->input('name')) : '';
        $managerId = $request->input('managerUserId');
        $divisionId = $request->input('divisionId');
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->error('Tên phòng ban phải từ 1 đến 120 ký tự.', 400);
        }
        if (DB::table('departments')->where('name', $name)->exists()) {
            return $this->error('Tên phòng ban này đã tồn tại.', 409);
        }
        if ($divisionId !== null && (! is_string($divisionId)
            || ! DB::table('divisions')->where('id', $divisionId)->exists()
            || ! OrganizationAccess::canManageDivision($current, $divisionId))) {
            return $this->error('Bộ phận được chọn không hợp lệ hoặc nằm ngoài phạm vi quản lý.', 403);
        }
        if (! OrganizationAccess::isOrganizationAdmin($current)
            && ($current->org_level !== 'division_head' || ! $divisionId)) {
            return $this->error('Chỉ admin, Tổng giám đốc hoặc trưởng bộ phận mới được tạo phòng ban.', 403);
        }
        if ($managerId !== null && (! is_string($managerId) || ! $this->activeUsersExist([$managerId]))) {
            return $this->error('Người quản lý phòng ban không hợp lệ.', 400);
        }
        if ($managerId && DB::table('departments')->where('manager_user_id', $managerId)->exists()) {
            return $this->error('Người này đang quản lý phòng ban khác.', 409);
        }
        if ($managerId) {
            $manager = DB::table('users')->where('id', $managerId)->first();
            if (! $manager || ! OrganizationAccess::canManageUser($current, $manager)
                || ! OrganizationAccess::canAssignLevel($current, 'department_head')
                || $manager->role === 'admin'
                || $manager->org_level !== 'employee'
                || $divisionId && $manager->department_id && ! DB::table('departments')
                    ->join('divisions', 'divisions.id', '=', 'departments.division_id')
                    ->where('departments.id', $manager->department_id)
                    ->where('divisions.id', $divisionId)
                    ->exists()) {
                return $this->error('Trưởng phòng phải là cấp dưới trong bộ phận đang quản lý.', 403);
            }
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $name, $managerId, $divisionId): void {
            DB::table('departments')->insert([
                'id' => $id,
                'name' => $name,
                'division_id' => $divisionId,
                'manager_user_id' => $managerId,
            ]);
            if ($managerId) {
                DB::table('users')->where('id', $managerId)->update([
                    'department_id' => $id,
                    'org_level' => 'department_head',
                ]);
                DB::table('team_members')->where('user_id', $managerId)->delete();
            }
        });
        $this->broadcastAccounts();

        return response()->json($this->findDepartment($id), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);
        $name = is_string($request->input('name')) ? trim($request->input('name')) : '';
        $managerId = $request->input('managerUserId');
        $divisionId = $request->input('divisionId');
        if ($name === '' || mb_strlen($name) > 120
            || ($managerId !== null && (! is_string($managerId) || ! $this->activeUsersExist([$managerId])))) {
            return $this->error('Thông tin phòng ban không hợp lệ.', 400);
        }
        $department = DB::table('departments')->where('id', $id)->first();
        if (! $department) {
            return $this->error('Không tìm thấy phòng ban.', 404);
        }
        if (! OrganizationAccess::canManageDepartment($current, $id)
            || $current->org_level === 'department_head' && ! OrganizationAccess::isSystemAdmin($current)) {
            return $this->error('Bạn không có quyền sửa phòng ban này.', 403);
        }
        if ($divisionId !== null && (! is_string($divisionId)
            || ! DB::table('divisions')->where('id', $divisionId)->exists()
            || ! OrganizationAccess::canManageDivision($current, $divisionId))) {
            return $this->error('Bộ phận được chọn không hợp lệ hoặc nằm ngoài phạm vi quản lý.', 403);
        }
        if (DB::table('departments')->where('name', $name)->where('id', '!=', $id)->exists()) {
            return $this->error('Tên phòng ban này đã tồn tại.', 409);
        }
        if ($managerId && DB::table('departments')
            ->where('manager_user_id', $managerId)->where('id', '!=', $id)->exists()) {
            return $this->error('Người này đang quản lý phòng ban khác.', 409);
        }
        if ($managerId && (string) $managerId !== (string) $department->manager_user_id) {
            $manager = DB::table('users')->where('id', $managerId)->first();
            if (! $manager || ! OrganizationAccess::canManageUser($current, $manager)
                || ! OrganizationAccess::canAssignLevel($current, 'department_head')
                || $manager->role === 'admin'
                || $manager->org_level !== 'employee') {
                return $this->error('Trưởng phòng phải là cấp dưới thuộc phạm vi quản lý của bạn.', 403);
            }
        }

        DB::transaction(function () use ($department, $id, $name, $managerId, $divisionId): void {
            if ($department->manager_user_id && (string) $department->manager_user_id !== (string) $managerId) {
                DB::table('users')
                    ->where('id', $department->manager_user_id)
                    ->where('department_id', $id)
                    ->update(['org_level' => 'employee']);
            }
            DB::table('departments')->where('id', $id)->update([
                'name' => $name,
                'division_id' => $divisionId,
                'manager_user_id' => $managerId,
            ]);
            if ($managerId) {
                DB::table('users')->where('id', $managerId)->update([
                    'department_id' => $id,
                    'org_level' => 'department_head',
                ]);
                DB::table('team_members')->where('user_id', $managerId)->delete();
            }
        });
        $this->broadcastAccounts();

        return response()->json($this->findDepartment($id));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);
        $department = DB::table('departments')->where('id', $id)->first();
        if (! $department) {
            return $this->error('Không tìm thấy phòng ban.', 404);
        }
        if (! OrganizationAccess::isOrganizationAdmin($current)) {
            return $this->error('Chỉ admin hoặc Tổng giám đốc mới được xóa phòng ban.', 403);
        }

        DB::transaction(function () use ($id): void {
            $managerId = DB::table('departments')->where('id', $id)->value('manager_user_id');
            $teamManagers = DB::table('teams')->where('department_id', $id)->whereNotNull('manager_user_id')->pluck('manager_user_id');
            DB::table('users')->where('department_id', $id)->update(['department_id' => null]);
            if ($managerId) {
                DB::table('users')->where('id', $managerId)->where('org_level', 'department_head')
                    ->update(['org_level' => 'employee']);
            }
            if ($teamManagers->isNotEmpty()) {
                DB::table('users')->whereIn('id', $teamManagers)->where('org_level', 'team_lead')
                    ->update(['org_level' => 'employee']);
            }
            DB::table('departments')->where('id', $id)->delete();
        });
        $this->broadcastAccounts();

        return response()->json(['ok' => true]);
    }

    public function users(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);
        $department = DB::table('departments')->where('id', $id)->first();
        if (! $department) {
            return $this->error('Không tìm thấy phòng ban.', 404);
        }
        if (! OrganizationAccess::canManageDepartment($current, $id)) {
            return $this->error('Bạn không có quyền xem phòng ban này.', 403);
        }

        $query = DB::table('users')
            ->where('active', 1)
            ->where(function ($builder) use ($id, $request, $current): void {
                $builder->where('department_id', $id);
                if ($request->boolean('includeUnassigned') && OrganizationAccess::isOrganizationAdmin($current)) {
                    $builder->orWhereNull('department_id');
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id as departmentId', 'role']);

        $permissions = DB::table('user_permissions')
            ->whereIn('user_id', $query->pluck('id'))
            ->get(['user_id', 'permission'])
            ->groupBy('user_id');

        return response()->json($query->map(fn ($user) => [
            ...((array) $user),
            'id' => $this->responseUserId($user->id),
            'permissions' => ($permissions->get($user->id) ?? collect())->pluck('permission')->all(),
        ]));
    }

    public function assignUser(Request $request, string $id, string $userId): JsonResponse
    {
        $current = $this->user($request);
        $department = DB::table('departments')->where('id', $id)->first();
        $target = DB::table('users')->where('id', $userId)->where('active', 1)->first();
        if (! $department || ! $target) {
            return $this->error('Không tìm thấy phòng ban hoặc tài khoản đang hoạt động.', 404);
        }
        if (! OrganizationAccess::canManageDepartment($current, $id)
            || ! OrganizationAccess::canManageUser($current, $target)) {
            return $this->error('Bạn không có quyền quản lý tài khoản này trong phòng ban.', 403);
        }
        if ((int) $target->id === (int) $department->manager_user_id) {
            return $this->error('Không thể thay đổi thành viên quản lý phòng ban.', 409);
        }

        DB::transaction(function () use ($userId, $id): void {
            DB::table('users')->where('id', $userId)->update(['department_id' => $id]);
            DB::table('team_members')->where('user_id', $userId)->delete();
        });
        $this->broadcastAccounts();

        return response()->json(['ok' => true]);
    }

    public function removeUser(Request $request, string $id, string $userId): JsonResponse
    {
        $current = $this->user($request);
        $department = DB::table('departments')->where('id', $id)->first();
        if (! $department) {
            return $this->error('Không tìm thấy phòng ban.', 404);
        }
        if (! OrganizationAccess::canManageDepartment($current, $id)) {
            return $this->error('Bạn không có quyền quản lý phòng ban này.', 403);
        }
        if ((int) $userId === (int) $department->manager_user_id) {
            return $this->error('Không thể xóa trưởng phòng khỏi phòng ban.', 409);
        }
        $target = DB::table('users')->where('id', $userId)->first();
        if (! $target || ! OrganizationAccess::canManageUser($current, $target)) {
            return $this->error('Bạn không có quyền quản lý tài khoản này.', 403);
        }
        if ((int) $target->department_id !== (int) $id) {
            return $this->error('Tài khoản không thuộc phòng ban này.', 404);
        }
        DB::transaction(function () use ($userId, $id): void {
            DB::table('team_members')->where('user_id', $userId)->delete();
            DB::table('users')->where('id', $userId)->where('department_id', $id)->update(['department_id' => null]);
        });
        $this->broadcastAccounts();

        return response()->json(['ok' => true]);
    }

    private function findDepartment(string $id): object
    {
        $department = DB::table('departments')
            ->leftJoin('users as managers', 'managers.id', '=', 'departments.manager_user_id')
            ->where('departments.id', $id)
            ->first([
                'departments.id',
                'departments.name',
                'departments.division_id as divisionId',
                'departments.manager_user_id as managerUserId',
                'managers.name as managerName',
            ]);
        if ($department) {
            $department->managerUserId = $this->responseUserId($department->managerUserId);
        }

        return $department;
    }

    private function broadcastAccounts(): void
    {
        WorkdeskBroadcast::users(
            DB::table('users')->where('active', 1)->pluck('id'),
            'accounts',
        );
    }
}
