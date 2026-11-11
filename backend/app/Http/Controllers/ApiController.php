<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\OrganizationAccess;

abstract class ApiController extends Controller
{
    protected const USER_PERMISSIONS = [
        'meeting_admin',
        'permit_approver',
        'permit_hr',
        'permit_guard',
        'pdf_signer',
        'meal_hr',
        'overtime_hr',
    ];

    protected function user(Request $request): object
    {
        return $request->attributes->get('workdesk_user');
    }

    protected function publicUser(object|array $user): array
    {
        $user = (array) $user;
        $id = $user['id'] ?? null;
        $permissions = $id
            ? DB::table('user_permissions')->where('user_id', $id)->orderBy('permission')->pluck('permission')->all()
            : [];
        $department = ! empty($user['department_id'])
            ? DB::table('departments')->where('id', $user['department_id'])->first(['id', 'name', 'manager_user_id', 'division_id'])
            : null;
        $division = $user['org_level'] === 'division_head'
            ? DB::table('divisions')->where('manager_user_id', $id)->first(['id', 'name'])
            : ($department?->division_id
                ? DB::table('divisions')->where('id', $department->division_id)->first(['id', 'name'])
                : null);
        $team = DB::table('team_members')
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $id)
            ->first(['teams.id', 'teams.name']);

        return [
            'id' => $this->responseUserId($id),
            'userCode' => $user['user_code'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => $user['role'] ?? null,
            'orgLevel' => $user['org_level'] ?? 'employee',
            'divisionId' => $division?->id,
            'divisionName' => $division?->name,
            'departmentId' => $department?->id,
            'departmentName' => $department?->name,
            'teamId' => $team?->id,
            'teamName' => $team?->name,
            'isDepartmentManager' => $department && (int) $department->manager_user_id === (int) $id,
            'permissions' => $permissions,
            'sealText' => $user['seal_text'] ?? null,
            'signatureName' => $user['signature_name'] ?? null,
            'color' => $user['color'] ?? null,
        ];
    }

    protected function responseUserId(mixed $id): ?string
    {
        return $id === null ? null : (string) $id;
    }

    protected function hasPermission(object $user, string $permission): bool
    {
        if (OrganizationAccess::isOrganizationAdmin($user)) {
            return true;
        }

        return DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('permission', $permission)
            ->exists();
    }

    protected function userHasPermission(int|string $userId, string $permission): bool
    {
        $user = DB::table('users')->where('id', $userId)->first(['id', 'role', 'org_level']);
        if ($permission === 'permit_hr') {
            return $user !== null && DB::table('user_permissions')
                ->where('user_id', $userId)
                ->where('permission', $permission)
                ->exists();
        }

        return $user !== null && $this->hasPermission($user, $permission);
    }

    protected function validPermissions(mixed $permissions): bool
    {
        return is_array($permissions)
            && count($permissions) <= count(self::USER_PERMISSIONS)
            && count(array_unique($permissions)) === count($permissions)
            && count(array_diff($permissions, self::USER_PERMISSIONS)) === 0;
    }

    protected function replacePermissions(int|string $userId, array $permissions): void
    {
        $userId = (int) $userId;
        DB::table('user_permissions')->where('user_id', $userId)->delete();
        if ($permissions) {
            DB::table('user_permissions')->insert(array_map(
                fn (string $permission): array => ['user_id' => $userId, 'permission' => $permission],
                $permissions,
            ));
        }
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }

    protected function activeUsersExist(array $userIds): bool
    {
        return count($userIds) === DB::table('users')
            ->whereIn('id', $userIds)
            ->where('active', 1)
            ->count();
    }

    protected function newSession(int|string $userId): string
    {
        $userId = (int) $userId;
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::table('auth_sessions')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'expires_at' => (int) floor(microtime(true) * 1000) + 12 * 60 * 60 * 1000,
        ]);

        return $token;
    }

    protected function sessionCookie(string $token, int $minutes = 720)
    {
        return cookie(
            'workdesk_session',
            $token,
            $minutes,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'strict',
        );
    }

    protected function normalizedEmail(mixed $value): ?string
    {
        return is_string($value) ? Str::lower(trim($value)) : null;
    }
}
