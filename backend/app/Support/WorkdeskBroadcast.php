<?php

namespace App\Support;

use App\Events\UserDataChanged;

class WorkdeskBroadcast
{
    public static function users(iterable $userIds, string $resource): void
    {
        $ids = [];
        foreach ($userIds as $userId) {
            if ($userId !== null && $userId !== '') {
                $ids[] = (string) $userId;
            }
        }

        foreach (array_unique($ids) as $userId) {
            UserDataChanged::dispatch($userId, $resource);
        }
    }
}
