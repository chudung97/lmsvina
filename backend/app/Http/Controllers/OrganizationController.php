<?php

namespace App\Http\Controllers;

use App\Support\OrganizationAccess;
use App\Support\WorkdeskBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $current = $this->user($request);
        $divisions = DB::table('divisions')
            ->leftJoin('users as managers', 'managers.id', '=', 'divisions.manager_user_id')
            ->when(! OrganizationAccess::isOrganizationAdmin($current), function ($query) use ($current): void {
                if ($current->org_level === 'division_head') {
                    $query->where('divisions.manager_user_id', $current->id);
                } elseif ($current->org_level === 'department_head') {
                    $query->whereIn('divisions.id', DB::table('departments')
                        ->where('manager_user_id', $current->id)
                        ->select('division_id'));
                } elseif ($current->org_level === 'team_lead') {
                    $query->whereIn('divisions.id', DB::table('departments')
                        ->join('teams', 'teams.department_id', '=', 'departments.id')
                        ->where('teams.manager_user_id', $current->id)
                        ->select('departments.division_id'));
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('divisions.name')
            ->get([
                'divisions.id',
                'divisions.name',
                'divisions.manager_user_id as managerUserId',
                'managers.name as managerName',
            ]);

        $departments = DB::table('departments')
            ->leftJoin('users as managers', 'managers.id', '=', 'departments.manager_user_id')
            ->when(! OrganizationAccess::isOrganizationAdmin($current), function ($query) use ($current): void {
                if ($current->org_level === 'division_head') {
                    $query->whereIn('departments.division_id', DB::table('divisions')
                        ->where('manager_user_id', $current->id)
                        ->select('id'));
                } elseif ($current->org_level === 'department_head') {
                    $query->where('departments.manager_user_id', $current->id);
                } elseif ($current->org_level === 'team_lead') {
                    $query->whereIn('departments.id', DB::table('teams')
                        ->where('manager_user_id', $current->id)
                        ->select('department_id'));
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('departments.name')
            ->get([
                'departments.id',
                'departments.name',
                'departments.division_id as divisionId',
                'departments.manager_user_id as managerUserId',
                'managers.name as managerName',
            ]);

        $teams = DB::table('teams')
            ->join('departments', 'departments.id', '=', 'teams.department_id')
            ->leftJoin('users as managers', 'managers.id', '=', 'teams.manager_user_id')
            ->when(! OrganizationAccess::isOrganizationAdmin($current), function ($query) use ($current): void {
                if ($current->org_level === 'division_head') {
                    $query->whereIn('departments.division_id', DB::table('divisions')
                        ->where('manager_user_id', $current->id)
                        ->select('id'));
                } elseif ($current->org_level === 'department_head') {
                    $query->whereIn('teams.department_id', DB::table('departments')
                        ->where('manager_user_id', $current->id)
                        ->select('id'));
                } elseif ($current->org_level === 'team_lead') {
                    $query->where('teams.manager_user_id', $current->id);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('departments.name')
            ->orderBy('teams.name')
            ->get([
                'teams.id',
                'teams.name',
                'teams.department_id as departmentId',
                'teams.manager_user_id as managerUserId',
                'managers.name as managerName',
            ]);

        foreach ([$divisions, $departments, $teams] as $units) {
            foreach ($units as $unit) {
                $unit->managerUserId = $this->responseUserId($unit->managerUserId);
            }
        }
        $teamMemberIds = $teams->isEmpty()
            ? collect()
            : DB::table('team_members')->whereIn('team_id', $teams->pluck('id'))
                ->get(['team_id', 'user_id'])
                ->groupBy('team_id')
                ->map(fn ($members) => $members->pluck('user_id')
                    ->map(fn ($userId) => $this->responseUserId($userId))
                    ->values());
        foreach ($teams as $team) {
            $team->memberUserIds = $teamMemberIds->get($team->id, collect());
        }

        return response()->json([
            'divisions' => $divisions,
            'departments' => $departments,
            'teams' => $teams,
        ]);
    }

    public function storeDivision(Request $request): JsonResponse
    {
        $current = $this->user($request);
        $name = $this->validName($request->input('name'));
        $managerId = $request->input('managerUserId');
        if ($managerId === '') {
            $managerId = null;
        }
        if ($name === null) {
            return $this->error('Tên bộ phận phải từ 1 đến 120 ký tự.', 400);
        }
        if (! OrganizationAccess::isOrganizationAdmin($current)) {
            return $this->error('Chỉ admin hoặc Tổng giám đốc mới được tạo bộ phận.', 403);
        }
        if (DB::table('divisions')->where('name', $name)->exists()) {
            return $this->error('Tên bộ phận này đã tồn tại.', 409);
        }
        if ($managerId !== null) {
            if (! is_string($managerId)) {
                return $this->error('Người quản lý không hợp lệ.', 400);
            }
            $manager = DB::table('users')->where('id', $managerId)->where('active', 1)->first();
            if (! $manager || ! OrganizationAccess::canManageUser($current, $manager)
                || ! OrganizationAccess::canAssignLevel($current, 'division_head')
                || $manager->role === 'admin'
                || $manager->org_level !== 'employee') {
                return $this->error('Trưởng bộ phận phải là nhân viên cấp dưới đang hoạt động.', 403);
            }
            $alreadyManaging = DB::table('divisions')->where('manager_user_id', $managerId)->exists()
                || DB::table('departments')->where('manager_user_id', $managerId)->exists()
                || DB::table('teams')->where('manager_user_id', $managerId)->exists();
            if ($alreadyManaging) {
                return $this->error('Người này đang quản lý một đơn vị khác.', 409);
            }
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $name, $managerId): void {
            DB::table('divisions')->insert([
                'id' => $id,
                'name' => $name,
                'manager_user_id' => $managerId,
            ]);
            if ($managerId) {
                DB::table('users')->where('id', $managerId)->update([
                    'org_level' => 'division_head',
                    'department_id' => null,
                ]);
                DB::table('team_members')->where('user_id', $managerId)->delete();
            }
        });
        $this->broadcastAccounts();

        return response()->json($this->findDivision($id), 201);
    }

    public function updateDivision(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);
        $name = $this->validName($request->input('name'));
        $division = DB::table('divisions')->where('id', $id)->first();
        $managerId = $request->input('managerUserId', $division?->manager_user_id);
        if ($managerId === '') {
            $managerId = null;
        }
        if ($name === null) {
            return $this->error('Tên bộ phận phải từ 1 đến 120 ký tự.', 400);
        }
        if (! OrganizationAccess::canManageDivision($current, $id)) {
            return $this->error('Bạn không có quyền sửa bộ phận này.', 403);
        }
        if (! $division) {
            return $this->error('Không tìm thấy bộ phận.', 404);
        }
        if (DB::table('divisions')->where('name', $name)->where('id', '!=', $id)->exists()) {
            return $this->error('Tên bộ phận này đã tồn tại.', 409);
        }
        if ($managerId !== null && ! is_string($managerId)) {
            return $this->error('Người quản lý không hợp lệ.', 400);
        }
        if ($managerId && (string) $managerId !== (string) $division->manager_user_id) {
            $manager = DB::table('users')->where('id', $managerId)->where('active', 1)->first();
            if (! $manager || ! OrganizationAccess::canManageUser($current, $manager)
                || ! OrganizationAccess::canAssignLevel($current, 'division_head')
                || $manager->role === 'admin'
                || $manager->org_level !== 'employee') {
                return $this->error('Trưởng bộ phận phải là nhân viên cấp dưới đang hoạt động.', 403);
            }
            $alreadyManaging = DB::table('divisions')->where('manager_user_id', $managerId)->where('id', '!=', $id)->exists()
                || DB::table('departments')->where('manager_user_id', $managerId)->exists()
                || DB::table('teams')->where('manager_user_id', $managerId)->exists();
            if ($alreadyManaging) {
                return $this->error('Người này đang quản lý một đơn vị khác.', 409);
            }
        }
        if ($division->manager_user_id && (string) $division->manager_user_id !== (string) $managerId
            && ! OrganizationAccess::isOrganizationAdmin($current)) {
            return $this->error('Chỉ admin hoặc Tổng giám đốc mới được thay đổi trưởng bộ phận hiện tại.', 403);
        }

        DB::transaction(function () use ($id, $name, $managerId, $division): void {
            DB::table('divisions')->where('id', $id)->update([
                'name' => $name,
                'manager_user_id' => $managerId,
            ]);
            if ($division->manager_user_id && (string) $division->manager_user_id !== (string) $managerId) {
                DB::table('users')->where('id', $division->manager_user_id)
                    ->where('org_level', 'division_head')
                    ->update(['org_level' => 'employee']);
            }
            if ($managerId && (string) $managerId !== (string) $division->manager_user_id) {
                DB::table('users')->where('id', $managerId)->update([
                    'org_level' => 'division_head',
                    'department_id' => null,
                ]);
                DB::table('team_members')->where('user_id', $managerId)->delete();
            }
        });
        $this->broadcastAccounts();

        return $this->findDivision($id);
    }

    public function deleteDivision(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);
        if (! OrganizationAccess::canManageDivision($current, $id)) {
            return $this->error('Bạn không có quyền xóa bộ phận này.', 403);
        }
        if (! DB::table('divisions')->where('id', $id)->exists()) {
            return $this->error('Không tìm thấy bộ phận.', 404);
        }
        if (DB::table('departments')->where('division_id', $id)->exists()) {
            return $this->error('Hãy chuyển các phòng ban ra khỏi bộ phận trước khi xóa.', 409);
        }

        DB::transaction(function () use ($id): void {
            $managerId = DB::table('divisions')->where('id', $id)->value('manager_user_id');
            DB::table('divisions')->where('id', $id)->delete();
            if ($managerId) {
                DB::table('users')->where('id', $managerId)->where('org_level', 'division_head')
                    ->update(['org_level' => 'employee']);
            }
        });
        $this->broadcastAccounts();

        return response()->json(['ok' => true]);
    }

    public function storeTeam(Request $request): JsonResponse
    {
        $current = $this->user($request);
        $departmentId = $request->input('departmentId');
        if (! is_string($departmentId) || $departmentId === '') {
            return $this->error('Phòng ban không hợp lệ.', 400);
        }
        if (! OrganizationAccess::canManageDepartment($current, $departmentId)) {
            return $this->error('Bạn không có quyền tạo team trong phòng ban này.', 403);
        }
        $department = DB::table('departments')->where('id', $departmentId)->first(['id', 'name']);
        if (! $department) {
            return $this->error('Không tìm thấy phòng ban.', 404);
        }
        $existingTeam = DB::table('teams')->where('department_id', $departmentId)->first();
        $memberIds = $this->validateTeamMembers(
            $request->input('memberUserIds', []),
            $departmentId,
            $current,
            $existingTeam?->id,
        );
        if ($memberIds instanceof JsonResponse) {
            return $memberIds;
        }

        $id = $existingTeam?->id ?? (string) Str::uuid();
        DB::transaction(function () use ($id, $departmentId, $department, $memberIds, $existingTeam): void {
            if ($existingTeam) {
                DB::table('teams')->where('id', $id)->update(['name' => $department->name]);
                DB::table('team_members')->where('team_id', $id)->delete();
            } else {
                DB::table('teams')->insert(['id' => $id, 'department_id' => $departmentId, 'name' => $department->name]);
            }
            if ($memberIds) {
                DB::table('users')->whereIn('id', $memberIds)
                    ->whereNull('department_id')
                    ->update(['department_id' => $departmentId]);
                DB::table('team_members')->insert(array_map(
                    fn (string $userId) => ['user_id' => $userId, 'team_id' => $id],
                    $memberIds,
                ));
            }
        });
        $this->broadcastAccounts();

        return response()->json([
            'id' => $id,
            'departmentId' => $departmentId,
            'name' => $department->name,
            'managerUserId' => $existingTeam ? $this->responseUserId($existingTeam->manager_user_id) : null,
            'memberUserIds' => $memberIds,
        ], $existingTeam ? 200 : 201);
    }

    public function updateTeam(Request $request, string $id): JsonResponse
    {
        $current = $this->user($request);
        $team = DB::table('teams')->where('id', $id)->first();
        if (! $team) {
            return $this->error('Không tìm thấy team.', 404);
        }
        if (! OrganizationAccess::canManageTeam($current, $id)) {
            return $this->error('Bạn không có quyền sửa team này.', 403);
        }
        $departmentName = DB::table('departments')->where('id', $team->department_id)->value('name');
        $memberIds = null;
        if ($request->exists('memberUserIds')) {
            $memberIds = $this->validateTeamMembers($request->input('memberUserIds'), $team->department_id, $current, $id);
            if ($memberIds instanceof JsonResponse) {
                return $memberIds;
            }
        }
        DB::transaction(function () use ($id, $departmentName, $memberIds, $team): void {
            DB::table('teams')->where('id', $id)->update(['name' => $departmentName]);
            if ($memberIds !== null) {
                DB::table('team_members')->where('team_id', $id)->delete();
                if ($memberIds) {
                    DB::table('users')->whereIn('id', $memberIds)
                        ->whereNull('department_id')
                        ->update(['department_id' => $team->department_id]);
                    DB::table('team_members')->insert(array_map(
                        fn (string $userId) => ['user_id' => $userId, 'team_id' => $id],
                        $memberIds,
                    ));
                }
            }
        });
        $this->broadcastAccounts();

        return response()->json([
            'id' => $id,
            'departmentId' => $team->department_id,
            'name' => $departmentName,
            'managerUserId' => $this->responseUserId($team->manager_user_id),
            'memberUserIds' => $memberIds ?? DB::table('team_members')->where('team_id', $id)->pluck('user_id')
                ->map(fn ($userId) => $this->responseUserId($userId)),
        ]);
    }

    public function deleteTeam(Request $request, string $id): JsonResponse
    {
        $team = DB::table('teams')->where('id', $id)->first();
        if (! $team) {
            return $this->error('Không tìm thấy team.', 404);
        }
        if (! OrganizationAccess::canManageTeam($this->user($request), $id)) {
            return $this->error('Bạn không có quyền xóa team này.', 403);
        }
        DB::transaction(function () use ($team, $id): void {
            DB::table('teams')->where('id', $id)->delete();
            if ($team->manager_user_id) {
                DB::table('users')->where('id', $team->manager_user_id)->where('org_level', 'team_lead')
                    ->update(['org_level' => 'employee']);
            }
        });
        $this->broadcastAccounts();

        return response()->json(['ok' => true]);
    }

    public function assignDivisionManager(Request $request, string $id): JsonResponse
    {
        return $this->assignManagerForUnit($request, 'division', $id);
    }

    public function assignDepartmentManager(Request $request, string $id): JsonResponse
    {
        return $this->assignManagerForUnit($request, 'department', $id);
    }

    public function assignTeamManager(Request $request, string $id): JsonResponse
    {
        return $this->assignManagerForUnit($request, 'team', $id);
    }

    private function assignManagerForUnit(Request $request, string $type, string $id): JsonResponse
    {
        $current = $this->user($request);
        $managerId = $request->input('managerUserId');
        $unit = match ($type) {
            'division' => DB::table('divisions')->where('id', $id)->first(),
            'department' => DB::table('departments')->where('id', $id)->first(),
            'team' => DB::table('teams')->where('id', $id)->first(),
            default => null,
        };
        if (! $unit) {
            return $this->error('Không tìm thấy đơn vị tổ chức.', 404);
        }
        if (! in_array($type, ['division', 'department', 'team'], true)) {
            return $this->error('Loại đơn vị tổ chức không hợp lệ.', 400);
        }
        $allowed = match ($type) {
            'division' => OrganizationAccess::canManageDivision($current, $id),
            'department' => OrganizationAccess::canManageDepartment($current, $id)
                && ($current->org_level !== 'department_head' || OrganizationAccess::isSystemAdmin($current)),
            'team' => OrganizationAccess::canManageTeam($current, $id),
        };
        if (! $allowed) {
            return $this->error('Bạn không có quyền phân công quản lý đơn vị này.', 403);
        }
        $managerTable = match ($type) {
            'division' => 'divisions',
            'department' => 'departments',
            'team' => 'teams',
        };
        if ($managerId === null || $managerId === '') {
            $previous = $unit->manager_user_id;
            DB::transaction(function () use ($managerTable, $id, $previous, $type): void {
                DB::table($managerTable)->where('id', $id)->update(['manager_user_id' => null]);
                if ($previous) {
                    $level = match ($type) {
                        'division' => 'division_head',
                        'department' => 'department_head',
                        'team' => 'team_lead',
                    };
                    DB::table('users')->where('id', $previous)->where('org_level', $level)->update(['org_level' => 'employee']);
                }
            });
            $this->broadcastAccounts();

            return response()->json(['ok' => true]);
        }
        if (! is_string($managerId)) {
            return $this->error('Người quản lý không hợp lệ.', 400);
        }
        $manager = DB::table('users')->where('id', $managerId)->where('active', 1)->first();
        if (! $manager) {
            return $this->error('Không tìm thấy tài khoản quản lý đang hoạt động.', 404);
        }
        $level = match ($type) {
            'division' => 'division_head',
            'department' => 'department_head',
            'team' => 'team_lead',
        };
        if (! OrganizationAccess::canManageUser($current, $manager)
            || ! OrganizationAccess::canAssignLevel($current, $level)
            || $manager->role === 'admin'
            || $level === 'division_head' && $manager->org_level === 'ceo') {
            return $this->error('Chỉ được bổ nhiệm cấp dưới thuộc phạm vi quản lý của bạn.', 403);
        }
        $existing = DB::table($managerTable)->where('manager_user_id', $managerId)->where('id', '!=', $id)->exists();
        if ($existing) {
            return $this->error('Người này đang quản lý đơn vị khác.', 409);
        }
        $oldUnit = DB::table('divisions')->where('manager_user_id', $managerId)->exists()
            || DB::table('departments')->where('manager_user_id', $managerId)->exists()
            || DB::table('teams')->where('manager_user_id', $managerId)->exists();
        if ($oldUnit && (int) $unit->manager_user_id !== (int) $managerId) {
            return $this->error('Người này đang quản lý một đơn vị khác.', 409);
        }
        $previousId = $unit->manager_user_id;
        if ($previousId && (int) $previousId !== (int) $managerId
            && ! OrganizationAccess::isSystemAdmin($current)
            && ! OrganizationAccess::isOrganizationAdmin($current)) {
            return $this->error('Chỉ admin hoặc Tổng giám đốc mới được thay đổi người quản lý hiện tại.', 403);
        }

        DB::transaction(function () use ($managerTable, $id, $managerId, $previousId, $manager, $level, $type, $unit): void {
            if ($previousId && (int) $previousId !== (int) $managerId) {
                $oldLevel = match ($type) {
                    'division' => 'division_head',
                    'department' => 'department_head',
                    'team' => 'team_lead',
                };
                DB::table('users')->where('id', $previousId)->where('org_level', $oldLevel)->update(['org_level' => 'employee']);
            }
            DB::table($managerTable)->where('id', $id)->update(['manager_user_id' => $managerId]);
            $departmentId = match ($type) {
                'division' => null,
                'department' => $id,
                'team' => $unit->department_id,
            };
            DB::table('users')->where('id', $managerId)->update([
                'org_level' => $level,
                'department_id' => $departmentId,
            ]);
            DB::table('team_members')->where('user_id', $managerId)->delete();
        });
        $this->broadcastAccounts();

        return response()->json(['ok' => true]);
    }

    private function findDivision(string $id): array
    {
        $division = DB::table('divisions')
            ->leftJoin('users as managers', 'managers.id', '=', 'divisions.manager_user_id')
            ->where('divisions.id', $id)
            ->first(['divisions.id', 'divisions.name', 'divisions.manager_user_id as managerUserId', 'managers.name as managerName']);

        return [...(array) $division, 'managerUserId' => $this->responseUserId($division->managerUserId)];
    }

    private function validateTeamMembers(
        mixed $value,
        string $departmentId,
        object $current,
        ?string $teamId = null,
    ): array|JsonResponse
    {
        if (! is_array($value)) {
            return $this->error('Danh sách thành viên team không hợp lệ.', 400);
        }
        $memberIds = [];
        foreach ($value as $userId) {
            if (! is_string($userId) && ! is_int($userId) || (string) $userId === '') {
                return $this->error('Danh sách thành viên team không hợp lệ.', 400);
            }
            $memberIds[] = (string) $userId;
        }
        if (count($memberIds) !== count(array_unique($memberIds))) {
            return $this->error('Không được chọn trùng thành viên team.', 400);
        }
        if (! $memberIds) {
            return [];
        }

        $eligibleUsers = DB::table('users')
            ->whereIn('id', $memberIds)
            ->where(function ($query) use ($departmentId): void {
                $query->where('department_id', $departmentId)
                    ->orWhereNull('department_id');
            })
            ->where('active', 1)
            ->where('role', '!=', 'admin')
            ->where('org_level', 'employee')
            ->get(['id', 'role', 'org_level', 'department_id']);
        if ($eligibleUsers->count() !== count($memberIds)) {
            return $this->error('Chỉ được chọn nhân viên đang hoạt động, chưa được phân quyền quản lý và chưa thuộc phòng ban khác.', 400);
        }
        foreach ($eligibleUsers as $eligibleUser) {
            $canAssignUnassignedEmployee = $eligibleUser->department_id === null
                && OrganizationAccess::canManageDepartment($current, $departmentId);
            if (! OrganizationAccess::canManageUser($current, $eligibleUser) && ! $canAssignUnassignedEmployee) {
                return $this->error('Bạn không có quyền thêm một hoặc nhiều nhân viên vào team này.', 403);
            }
        }
        $alreadyAssigned = DB::table('team_members')
            ->whereIn('user_id', $memberIds)
            ->when($teamId !== null, fn ($query) => $query->where('team_id', '!=', $teamId))
            ->exists();
        if ($alreadyAssigned) {
            return $this->error('Một hoặc nhiều nhân viên đã thuộc team khác.', 409);
        }

        return $memberIds;
    }

    private function validName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $name = trim($value);

        return $name !== '' && mb_strlen($name) <= 120 ? $name : null;
    }

    private function broadcastAccounts(): void
    {
        WorkdeskBroadcast::users(DB::table('users')->where('active', 1)->pluck('id'), 'accounts');
    }
}
