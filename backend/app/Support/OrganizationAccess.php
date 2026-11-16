<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phân cấp tổ chức: Admin hệ thống → Tổng giám đốc → Quản lý bộ phận → Trưởng bộ phận
 * → Trưởng phòng → Team lead → Nhân viên/công nhân.
 *
 * - Trưởng bộ phận: divisions.manager_user_id, mỗi người một bộ phận.
 * - Quản lý bộ phận: divisions.division_manager_user_id, một người quản lý được nhiều bộ phận
 *   (mỗi bộ phận có tối đa một Quản lý bộ phận). Quản lý bộ phận đứng trên Trưởng bộ phận nên
 *   được sửa bộ phận, đổi Trưởng bộ phận và quản lý mọi phòng ban/nhân viên trong các bộ phận đó.
 */
class OrganizationAccess
{
    public const LEVELS = [
        'employee' => 0,
        'team_lead' => 1,
        'department_head' => 2,
        'division_head' => 3,
        'division_manager' => 4,
        'ceo' => 5,
    ];

    /** Cấp quản lý theo bộ phận. */
    public const DIVISION_LEVELS = ['division_manager', 'division_head'];

    /** Các cấp có người dưới quyền. */
    public const MANAGER_LEVELS = ['ceo', 'division_manager', 'division_head', 'department_head', 'team_lead'];

    /** Cột trong bảng divisions ứng với từng cấp quản lý bộ phận. */
    public const DIVISION_COLUMNS = [
        'division_manager' => 'division_manager_user_id',
        'division_head' => 'manager_user_id',
    ];

    public static function isSystemAdmin(object $user): bool
    {
        return $user->role === 'admin';
    }

    public static function isOrganizationAdmin(object $user): bool
    {
        return self::isSystemAdmin($user) || $user->org_level === 'ceo';
    }

    public static function isDivisionLevel(object $user): bool
    {
        return in_array($user->org_level ?? 'employee', self::DIVISION_LEVELS, true);
    }

    public static function rank(object $user): int
    {
        return self::isSystemAdmin($user) ? 6 : (self::LEVELS[$user->org_level ?? 'employee'] ?? 0);
    }

    /** Truy vấn con: id các bộ phận người này quản lý (Quản lý bộ phận: có thể nhiều; Trưởng bộ phận: một). */
    public static function managedDivisionIds(object $user): Builder
    {
        $column = self::DIVISION_COLUMNS[$user->org_level ?? ''] ?? null;

        return $column
            ? DB::table('divisions')->where($column, $user->id)->select('id')
            : DB::table('divisions')->whereRaw('1 = 0')->select('id');
    }

    /**
     * Người này có đang quản lý đơn vị nào không. $ignoreDivisionManager: bỏ qua vai trò Quản lý bộ phận
     * (một Quản lý bộ phận được nhận thêm bộ phận khác).
     */
    public static function managesAnyUnit(int|string $userId, bool $ignoreDivisionManager = false): bool
    {
        $units = [
            ['divisions', 'manager_user_id'],
            ['departments', 'manager_user_id'],
            ['teams', 'manager_user_id'],
        ];
        if (! $ignoreDivisionManager) {
            $units[] = ['divisions', 'division_manager_user_id'];
        }
        foreach ($units as [$table, $column]) {
            if (DB::table($table)->where($column, $userId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Hạ người không còn quản lý bộ phận nào về nhân viên (sau khi gỡ khỏi bộ phận). */
    public static function demoteDivisionManagerIfIdle(int|string|null $userId): void
    {
        if ($userId !== null && ! DB::table('divisions')->where('division_manager_user_id', $userId)->exists()) {
            DB::table('users')->where('id', $userId)->where('org_level', 'division_manager')
                ->update(['org_level' => 'employee']);
        }
    }

    public static function visibleUsersQuery(object $user): Builder
    {
        $query = DB::table('users')->leftJoin('departments', 'departments.id', '=', 'users.department_id');
        if (self::isOrganizationAdmin($user)) {
            return $query;
        }

        if (self::isDivisionLevel($user)) {
            $divisionIds = self::managedDivisionIds($user);

            return $query->where(function (Builder $builder) use ($divisionIds, $user): void {
                $builder->whereIn('departments.division_id', $divisionIds)
                    ->orWhere('users.id', $user->id);
                if ($user->org_level === 'division_manager') {
                    // Trưởng bộ phận không thuộc phòng ban nào: lấy từ các bộ phận đang quản lý.
                    $builder->orWhereIn('users.id', DB::table('divisions')
                        ->where('division_manager_user_id', $user->id)
                        ->whereNotNull('manager_user_id')
                        ->select('manager_user_id'));
                }
            });
        }

        if ($user->org_level === 'department_head') {
            $departmentIds = DB::table('departments')->where('manager_user_id', $user->id)->select('id');

            return $query->where(function (Builder $builder) use ($departmentIds, $user): void {
                $builder->whereIn('users.department_id', $departmentIds)
                    ->orWhere('users.id', $user->id);
            });
        }

        if ($user->org_level === 'team_lead') {
            $teamIds = DB::table('teams')->where('manager_user_id', $user->id)->select('id');

            return $query->leftJoin('team_members', 'team_members.user_id', '=', 'users.id')
                ->where(function (Builder $builder) use ($teamIds, $user): void {
                    $builder->whereIn('team_members.team_id', $teamIds)
                        ->orWhere('users.id', $user->id);
                });
        }

        return $query->where('users.id', $user->id);
    }

    public static function canManageUser(object $actor, object $target): bool
    {
        if (self::isSystemAdmin($actor)) {
            return true;
        }
        if ((int) $actor->id === (int) $target->id || self::rank($actor) <= self::rank($target)) {
            return false;
        }
        if ($actor->org_level === 'ceo') {
            return true;
        }
        if (self::isDivisionLevel($actor)) {
            $inDivision = $target->department_id !== null && DB::table('departments')
                ->whereIn('division_id', self::managedDivisionIds($actor))
                ->where('id', $target->department_id)
                ->exists();
            $isOwnDivisionHead = $actor->org_level === 'division_manager' && DB::table('divisions')
                ->where('division_manager_user_id', $actor->id)
                ->where('manager_user_id', $target->id)
                ->exists();

            return $inDivision || $isOwnDivisionHead;
        }

        return match ($actor->org_level) {
            'department_head' => DB::table('departments')
                ->where('manager_user_id', $actor->id)
                ->where('id', $target->department_id)
                ->exists(),
            'team_lead' => DB::table('teams')
                ->join('team_members', 'team_members.team_id', '=', 'teams.id')
                ->where('teams.manager_user_id', $actor->id)
                ->where('team_members.user_id', $target->id)
                ->exists(),
            default => false,
        };
    }

    /** Quản lý phạm vi bộ phận: phòng ban, team và nhân viên bên trong. */
    public static function canManageDivision(object $actor, string $divisionId): bool
    {
        return self::isOrganizationAdmin($actor)
            || self::isDivisionLevel($actor)
                && self::managedDivisionIds($actor)->where('id', $divisionId)->exists();
    }

    /** Sửa chính bộ phận (tên, Trưởng bộ phận) hoặc xóa bộ phận. */
    public static function canEditDivision(object $actor, string $divisionId): bool
    {
        return self::canManageDivision($actor, $divisionId);
    }

    public static function canManageDepartment(object $actor, string $departmentId): bool
    {
        if (self::isOrganizationAdmin($actor)) {
            return true;
        }
        if (self::isDivisionLevel($actor)) {
            return DB::table('departments')
                ->where('id', $departmentId)
                ->whereIn('division_id', self::managedDivisionIds($actor))
                ->exists();
        }

        return $actor->org_level === 'department_head' && DB::table('departments')
            ->where('id', $departmentId)
            ->where('manager_user_id', $actor->id)
            ->exists();
    }

    public static function canManageTeam(object $actor, string $teamId): bool
    {
        if (self::isOrganizationAdmin($actor)) {
            return true;
        }

        $query = DB::table('teams')->where('teams.id', $teamId);
        if (self::isDivisionLevel($actor)) {
            return $query
                ->join('departments', 'departments.id', '=', 'teams.department_id')
                ->whereIn('departments.division_id', self::managedDivisionIds($actor))
                ->exists();
        }

        return match ($actor->org_level) {
            'department_head' => $query
                ->join('departments', 'departments.id', '=', 'teams.department_id')
                ->where('departments.manager_user_id', $actor->id)
                ->exists(),
            'team_lead' => $query->where('teams.manager_user_id', $actor->id)->exists(),
            default => false,
        };
    }

    public static function canAssignLevel(object $actor, string $targetLevel): bool
    {
        return self::rank($actor) > (self::LEVELS[$targetLevel] ?? 0);
    }
}
