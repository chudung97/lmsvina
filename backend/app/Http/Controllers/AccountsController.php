<?php

namespace App\Http\Controllers;

use App\Support\OrganizationAccess;
use App\Support\WorkdeskBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountsController extends ApiController
{
    public function listUsers(Request $request): JsonResponse
    {
        $current = $this->user($request);
        if ($current->role !== 'admin' && $current->org_level === 'employee') {
            return $this->error('Bạn không có quyền xem danh sách tài khoản.', 403);
        }

        $users = OrganizationAccess::visibleUsersQuery($current)
            ->when($current->org_level === 'ceo' && $current->role !== 'admin', fn ($query) => $query->where('users.role', '!=', 'admin'))
            ->leftJoin('divisions', 'divisions.id', '=', 'departments.division_id')
            ->orderByDesc('active')
            ->orderBy('users.name')
            ->get([
                'users.id', 'users.user_code', 'users.name', 'users.email', 'users.role', 'users.org_level', 'users.active',
                'users.department_id as departmentId', 'users.created_at as createdAt',
                'departments.division_id as divisionId', 'divisions.name as divisionName',
                'departments.name as departmentName', 'departments.manager_user_id as departmentManagerId',
            ]);
        $teams = DB::table('team_members')
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->whereIn('team_members.user_id', $users->pluck('id'))
            ->get(['team_members.user_id', 'teams.id as teamId', 'teams.name as teamName'])
            ->keyBy('user_id');
        $permissions = DB::table('user_permissions')
            ->whereIn('user_id', $users->pluck('id'))
            ->get(['user_id', 'permission'])
            ->groupBy('user_id');

        return response()->json($users->map(fn ($user) => [
            ...((array) $user),
            'id' => $this->responseUserId($user->id),
            'orgLevel' => $user->org_level,
            'departmentManagerId' => $this->responseUserId($user->departmentManagerId),
            'teamId' => $this->responseUserId($teams->get($user->id)?->teamId),
            'teamName' => $teams->get($user->id)?->teamName,
            'active' => (bool) $user->active,
            'isDepartmentManager' => $user->departmentManagerId === $user->id,
            'permissions' => ($permissions->get($user->id) ?? collect())->pluck('permission')->all(),
        ]));
    }

    public function createUser(Request $request): JsonResponse
    {
        $current = $this->user($request);
        if (! OrganizationAccess::isOrganizationAdmin($current)) {
            return $this->error('Chỉ admin hoặc Tổng giám đốc mới được tạo tài khoản mới.', 403);
        }

        $name = is_string($request->input('name')) ? trim($request->input('name')) : '';
        $email = $this->normalizedEmail($request->input('email')) ?? '';
        $password = $request->input('password');
        $departmentId = $request->input('departmentId');
        $permissions = $request->input('permissions', []);
        $role = $request->input('role', 'member');
        if ($role === 'admin' && ! OrganizationAccess::isSystemAdmin($current)) {
            return $this->error('Chỉ admin hệ thống mới được tạo tài khoản admin.', 403);
        }
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->error('Tên tài khoản phải từ 1 đến 120 ký tự.', 400);
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            return $this->error('Địa chỉ email không hợp lệ.', 400);
        }
        if (! is_string($password) || mb_strlen($password) < 6 || mb_strlen($password) > 1024) {
            return $this->error('Mật khẩu phải có từ 6 ký tự trở lên.', 400);
        }
        if (($departmentId !== null && (! is_string($departmentId)
            || ! DB::table('departments')->where('id', $departmentId)->exists()))
            || ! $this->validPermissions($permissions)
            || ! in_array($role, ['admin', 'member'], true)) {
            return $this->error('Phòng ban hoặc quyền tài khoản không hợp lệ.', 400);
        }
        if (DB::table('users')->where('email', $email)->exists()) {
            return $this->error('Email này đã được sử dụng.', 409);
        }

        $id = (int) DB::table('users')->insertGetId([
            'name' => $name,
            'seal_text' => mb_strtoupper(mb_substr($name, 0, 1)),
            'signature_name' => $name,
            'color' => '#0f766e',
            'email' => $email,
            'password_hash' => Hash::make($password),
            'role' => $role,
            'department_id' => $departmentId,
            'active' => 1,
        ]);
        $this->replacePermissions($id, $permissions);
        $this->broadcastAll('accounts');

        return response()->json([
            'id' => $this->responseUserId($id),
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'active' => true,
            'departmentId' => $departmentId,
            'permissions' => $permissions,
        ], 201);
    }

    public function updateUserAccess(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);
        $departmentId = $request->input('departmentId');
        $divisionId = $request->input('divisionId');
        $teamId = $request->input('teamId');
        $orgLevel = $request->input('orgLevel');
        $permissions = $request->input('permissions');
        if (! is_string($orgLevel)
            || ! in_array($orgLevel, ['ceo', 'division_head', 'department_head', 'team_lead', 'employee'], true)
            || ($departmentId !== null && ! is_string($departmentId))
            || ($divisionId !== null && ! is_string($divisionId))
            || ($teamId !== null && ! is_string($teamId))
            || ! $this->validPermissions($permissions)) {
            return $this->error('Cấp tổ chức, đơn vị hoặc quyền nghiệp vụ không hợp lệ.', 400);
        }

        $userId = (int) $id;
        $target = DB::table('users')->where('id', $userId)->first();
        if (! $target) {
            return $this->error('Không tìm thấy tài khoản.', 404);
        }
        if (! OrganizationAccess::canManageUser($current, $target)
            || ! OrganizationAccess::canAssignLevel($current, $orgLevel)) {
            return $this->error('Bạn chỉ được phân quyền cho cấp dưới thuộc phạm vi quản lý của mình.', 403);
        }
        $adminOnlyPermissions = [
            'meal_hr' => 'Chỉ admin hệ thống được cấp hoặc thu hồi quyền HR báo cơm.',
            'overtime_hr' => 'Chỉ admin hệ thống được cấp hoặc thu hồi quyền HR chốt công tăng ca.',
        ];
        if (! OrganizationAccess::isSystemAdmin($current)) {
            $currentPermissions = DB::table('user_permissions')
                ->where('user_id', $userId)
                ->whereIn('permission', array_keys($adminOnlyPermissions))
                ->pluck('permission')
                ->all();
            foreach ($adminOnlyPermissions as $permission => $message) {
                if (in_array($permission, $permissions, true) !== in_array($permission, $currentPermissions, true)) {
                    return $this->error($message, 403);
                }
            }
        }
        if ($target->role === 'admin') {
            return $this->error('Tài khoản admin hệ thống không thuộc cây phân cấp tổ chức.', 409);
        }
        if ($orgLevel === 'ceo' && ! OrganizationAccess::isSystemAdmin($current)) {
            return $this->error('Chỉ admin hệ thống mới được bổ nhiệm Tổng giám đốc.', 403);
        }

        $team = $teamId ? DB::table('teams')->where('id', $teamId)->first() : null;
        if ($teamId && ! $team) {
            return $this->error('Không tìm thấy team được chọn.', 404);
        }
        if ($team && $departmentId !== null && $team->department_id !== $departmentId) {
            return $this->error('Team phải thuộc phòng ban đã chọn.', 400);
        }
        if ($team) {
            $departmentId = $team->department_id;
        }
        $department = $departmentId ? DB::table('departments')->where('id', $departmentId)->first() : null;
        if ($departmentId && ! $department) {
            return $this->error('Không tìm thấy phòng ban được chọn.', 404);
        }
        if ($divisionId !== null && ! DB::table('divisions')->where('id', $divisionId)->exists()) {
            return $this->error('Không tìm thấy bộ phận được chọn.', 404);
        }

        if (! OrganizationAccess::isSystemAdmin($current) && ! OrganizationAccess::isOrganizationAdmin($current)) {
            if ($orgLevel === 'division_head' || $orgLevel === 'ceo') {
                return $this->error('Bạn không được bổ nhiệm cấp quản lý ngang hoặc cao hơn mình.', 403);
            }
            if ($departmentId && ! OrganizationAccess::canManageDepartment($current, $departmentId)) {
                return $this->error('Phòng ban nằm ngoài phạm vi quản lý của bạn.', 403);
            }
            if ($teamId && ! OrganizationAccess::canManageTeam($current, $teamId)) {
                return $this->error('Team nằm ngoài phạm vi quản lý của bạn.', 403);
            }
        }

        $unitId = match ($orgLevel) {
            'division_head' => $divisionId,
            'department_head' => $departmentId,
            'team_lead' => $teamId,
            default => null,
        };
        if (in_array($orgLevel, ['division_head', 'department_head', 'team_lead'], true) && ! $unitId) {
            return $this->error('Hãy gán quản lý vào đúng bộ phận, phòng ban hoặc team.', 400);
        }
        if ($orgLevel === 'division_head' && ($departmentId || $teamId)) {
            return $this->error('Trưởng bộ phận không thuộc riêng một phòng ban hoặc team.', 400);
        }
        if ($orgLevel === 'department_head' && $teamId) {
            return $this->error('Trưởng phòng không thuộc một team riêng.', 400);
        }
        if ($orgLevel === 'team_lead' && ! $team) {
            return $this->error('Team lead phải được gán vào một team.', 400);
        }
        if ($orgLevel === 'employee' && $divisionId !== null) {
            return $this->error('Nhân viên được gán phòng ban/team, không gán trực tiếp bộ phận.', 400);
        }
        if ($department && $divisionId && $department->division_id !== $divisionId) {
            return $this->error('Phòng ban không thuộc bộ phận đã chọn.', 400);
        }
        if ($orgLevel === 'division_head' && $department) {
            return $this->error('Trưởng bộ phận không được gán phòng ban riêng.', 400);
        }
        if ($orgLevel === 'department_head' && ! OrganizationAccess::canManageDepartment($current, $departmentId)
            && ! OrganizationAccess::isOrganizationAdmin($current)) {
            return $this->error('Không thể bổ nhiệm trưởng phòng ngoài phạm vi quản lý.', 403);
        }
        if ($orgLevel === 'team_lead' && ! OrganizationAccess::canManageTeam($current, $teamId)
            && ! OrganizationAccess::isOrganizationAdmin($current)) {
            return $this->error('Không thể bổ nhiệm team lead ngoài phạm vi quản lý.', 403);
        }
        if ($orgLevel === 'division_head' && ! OrganizationAccess::canManageDivision($current, $divisionId)
            && ! OrganizationAccess::isOrganizationAdmin($current)) {
            return $this->error('Không thể bổ nhiệm trưởng bộ phận ngoài phạm vi quản lý.', 403);
        }
        if ($orgLevel === 'ceo' && DB::table('users')->where('org_level', 'ceo')->where('id', '!=', $userId)->exists()) {
            return $this->error('Đã có Tổng giám đốc được bổ nhiệm.', 409);
        }

        $managerTable = match ($orgLevel) {
            'division_head' => ['divisions', 'id', $divisionId],
            'department_head' => ['departments', 'id', $departmentId],
            'team_lead' => ['teams', 'id', $teamId],
            default => null,
        };
        if ($managerTable && DB::table($managerTable[0])
            ->where($managerTable[1], $managerTable[2])
            ->whereNotNull('manager_user_id')
            ->where('manager_user_id', '!=', $userId)
            ->exists()) {
            return $this->error('Đơn vị này đã có người quản lý.', 409);
        }
        $managedUnitExists = DB::table('divisions')->where('manager_user_id', $userId)->exists()
            || DB::table('departments')->where('manager_user_id', $userId)->exists()
            || DB::table('teams')->where('manager_user_id', $userId)->exists();
        if ($managedUnitExists && ! $managerTable) {
            return $this->error('Chỉ có thể hạ cấp sau khi chỉ định người quản lý thay thế.', 409);
        }
        if ($managedUnitExists && $managerTable
            && ! DB::table($managerTable[0])->where($managerTable[1], $managerTable[2])->where('manager_user_id', $userId)->exists()) {
            return $this->error('Hãy chỉ định người quản lý thay thế trước khi chuyển người này.', 409);
        }

        DB::transaction(function () use ($userId, $departmentId, $teamId, $orgLevel, $permissions, $managerTable): void {
            DB::table('users')->where('id', $userId)->update([
                'department_id' => $departmentId,
                'org_level' => $orgLevel,
            ]);
            DB::table('team_members')->where('user_id', $userId)->delete();
            if ($teamId && $orgLevel === 'employee') {
                DB::table('team_members')->insert(['user_id' => $userId, 'team_id' => $teamId]);
            }
            DB::table('divisions')->where('manager_user_id', $userId)->update(['manager_user_id' => null]);
            DB::table('departments')->where('manager_user_id', $userId)->update(['manager_user_id' => null]);
            DB::table('teams')->where('manager_user_id', $userId)->update(['manager_user_id' => null]);
            if ($managerTable) {
                DB::table($managerTable[0])->where($managerTable[1], $managerTable[2])->update(['manager_user_id' => $userId]);
            }
            $this->replacePermissions($userId, $permissions);
        });
        WorkdeskBroadcast::users(DB::table('users')->where('active', 1)->pluck('id'), 'accounts');

        return response()->json(['ok' => true]);
    }

    public function updateAdminProfile(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);

        $name = is_string($request->input('name')) ? trim($request->input('name')) : '';
        $email = $this->normalizedEmail($request->input('email'));
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->error('Tên tài khoản phải từ 1 đến 120 ký tự.', 400);
        }
        if ($email !== null && $email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254)) {
            return $this->error('Địa chỉ email không hợp lệ.', 400);
        }
        $userId = (int) $id;
        $target = DB::table('users')->where('id', $userId)->first();
        if (! $target) {
            return $this->error('Không tìm thấy tài khoản.', 404);
        }
        if (! OrganizationAccess::canManageUser($current, $target)) {
            return $this->error('Bạn không có quyền quản lý tài khoản này.', 403);
        }
        if ((int) $target->id === (int) $current->id) {
            return $this->error('Hãy cập nhật hồ sơ của chính bạn trong mục Hồ sơ & bảo mật.', 409);
        }
        if ($email !== null && $email !== '' && DB::table('users')->where('email', $email)->where('id', '!=', $userId)->exists()) {
            return $this->error('Email này đã được sử dụng.', 409);
        }

        $changes = [
            'name' => $name,
            'email' => $email === '' ? null : $email,
        ];
        if ($target->signature_name === $target->name) {
            $changes['signature_name'] = $name;
        }
        DB::table('users')->where('id', $userId)->update($changes);
        $updated = DB::table('users')
            ->where('id', $userId)
            ->first(['id', 'user_code', 'name', 'email', 'role', 'active', 'created_at as createdAt']);
        $this->broadcastAll('accounts');

        return response()->json([
            ...((array) $updated),
            'id' => $this->responseUserId($updated->id),
            'active' => (bool) $updated->active,
        ]);
    }

    public function updateAdminPassword(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);

        $password = $request->input('password');
        if (! is_string($password) || mb_strlen($password) < 6 || mb_strlen($password) > 1024) {
            return $this->error('Mật khẩu phải có từ 6 ký tự trở lên.', 400);
        }
        $userId = (int) $id;
        $target = DB::table('users')
            ->where('id', $userId)
            ->where('active', 1)
            ->first(['id', 'role', 'org_level', 'department_id']);
        if (! $target) {
            return $this->error('Không tìm thấy tài khoản đang hoạt động.', 404);
        }
        if (! OrganizationAccess::canManageUser($current, $target)) {
            return $this->error('Bạn không có quyền quản lý tài khoản này.', 403);
        }
        if ((int) $target->id === (int) $current->id) {
            return $this->error('Hãy đổi mật khẩu của chính bạn trong mục Bảo mật.', 409);
        }

        DB::transaction(function () use ($target, $password): void {
            DB::table('users')->where('id', $target->id)->update([
                'password_hash' => Hash::make($password),
            ]);
            DB::table('auth_sessions')->where('user_id', $target->id)->delete();
        });
        WorkdeskBroadcast::users([$target->id], 'accounts');

        return response()->json(['ok' => true]);
    }

    public function updateOwnProfile(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $name = is_string($request->input('name')) ? trim($request->input('name')) : '';
        $email = $this->normalizedEmail($request->input('email')) ?? '';
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->error('Tên tài khoản phải từ 1 đến 120 ký tự.', 400);
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            return $this->error('Địa chỉ email không hợp lệ.', 400);
        }
        if (DB::table('users')->where('email', $email)->where('id', '!=', $user->id)->exists()) {
            return $this->error('Email này đã được sử dụng.', 409);
        }

        $changes = [
            'name' => $name,
            'email' => $email,
        ];
        if ($user->signature_name === $user->name) {
            $changes['signature_name'] = $name;
        }
        DB::table('users')->where('id', $user->id)->update($changes);
        $updated = DB::table('users')->where('id', $user->id)->first();
        $this->broadcastAll('accounts');

        return response()->json(['user' => $this->publicUser($updated)]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $currentPassword = $request->input('currentPassword');
        $newPassword = $request->input('newPassword');
        if (! is_string($newPassword) || mb_strlen($newPassword) < 6 || mb_strlen($newPassword) > 1024) {
            return $this->error('Mật khẩu mới phải có từ 6 ký tự trở lên.', 400);
        }
        if (! is_string($currentPassword) || ! Hash::check($currentPassword, $user->password_hash)) {
            return $this->error('Mật khẩu hiện tại không chính xác.', 403);
        }

        DB::transaction(function () use ($user, $newPassword): void {
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make($newPassword),
            ]);
            DB::table('auth_sessions')->where('user_id', $user->id)->delete();
        });
        $token = $this->newSession($user->id);

        return response()->json(['ok' => true])->withCookie($this->sessionCookie($token));
    }

    private function broadcastAll(string $resource): void
    {
        WorkdeskBroadcast::users(
            DB::table('users')->where('active', 1)->pluck('id'),
            $resource,
        );
    }
}
