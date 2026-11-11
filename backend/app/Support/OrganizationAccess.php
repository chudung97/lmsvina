<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OrganizationAccess
{
    private const LEVELS = [
        'employee' => 0,
        'team_lead' => 1,
        'department_head' => 2,
        'division_head' => 3,
        'ceo' => 4,
    ];

    public static function isSystemAdmin(object $user): bool
    {
        return $user->role === 'admin';
    }

    public static function isOrganizationAdmin(object $user): bool
    {
        return self::isSystemAdmin($user) || $user->org_level === 'ceo';
    }

    public static function rank(object $user): int
    {
        return self::isSystemAdmin($user) ? 5 : (self::LEVELS[$user->org_level ?? 'employee'] ?? 0);
    }

    public static function visibleUsersQuery(object $user): Builder
    {
        $query = DB::table('users')->leftJoin('departments', 'departments.id', '=', 'users.department_id');
        if (self::isOrganizationAdmin($user)) {
            return $query;
        }

        if ($user->org_level === 'division_head') {
            $divisionIds = DB::table('divisions')->where('manager_user_id', $user->id)->select('id');

            return $query->where(function (Builder $builder) use ($divisionIds, $user): void {
                $builder->whereIn('departments.division_id', $divisionIds)
                    ->orWhere('users.id', $user->id);
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
        if ($actor->id === $target->id || self::rank($actor) <= self::rank($target)) {
            return false;
        }
        if ($actor->org_level === 'ceo') {
            return true;
        }

        return match ($actor->org_level) {
            'division_head' => DB::table('departments')
                ->join('divisions', 'divisions.id', '=', 'departments.division_id')
                ->where('divisions.manager_user_id', $actor->id)
                ->where('departments.id', $target->department_id)
                ->exists(),
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

    public static function canManageDivision(object $actor, string $divisionId): bool
    {
        return self::isOrganizationAdmin($actor)
            || $actor->org_level === 'division_head'
                && DB::table('divisions')->where('id', $divisionId)->where('manager_user_id', $actor->id)->exists();
    }

    public static function canManageDepartment(object $actor, string $departmentId): bool
    {
        if (self::isOrganizationAdmin($actor)) {
            return true;
        }

        return match ($actor->org_level) {
            'division_head' => DB::table('departments')
                ->join('divisions', 'divisions.id', '=', 'departments.division_id')
                ->where('departments.id', $departmentId)
                ->where('divisions.manager_user_id', $actor->id)
                ->exists(),
            'department_head' => DB::table('departments')
                ->where('id', $departmentId)
                ->where('manager_user_id', $actor->id)
                ->exists(),
            default => false,
        };
    }

    public static function canManageTeam(object $actor, string $teamId): bool
    {
        if (self::isOrganizationAdmin($actor)) {
            return true;
        }

        $query = DB::table('teams')->where('teams.id', $teamId);

        return match ($actor->org_level) {
            'division_head' => $query
                ->join('departments', 'departments.id', '=', 'teams.department_id')
                ->join('divisions', 'divisions.id', '=', 'departments.division_id')
                ->where('divisions.manager_user_id', $actor->id)
                ->exists(),
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
