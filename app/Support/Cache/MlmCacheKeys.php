<?php

namespace App\Support\Cache;

/**
 * Claves centralizadas (prefijo global en config/cache.php → CACHE_PREFIX).
 */
final class MlmCacheKeys
{
    public const TAG_DASHBOARD = 'mlm:dashboard';

    public const TAG_WALLET = 'mlm:wallet';

    public const TAG_BINARY = 'mlm:binary';

    public const TAG_RANKS = 'mlm:ranks';

    public const TAG_ORDERS = 'mlm:orders';

    public static function adminDashboard(): string
    {
        return 'admin:dashboard:v1';
    }

    public static function walletBalance(int $userId): string
    {
        return 'wallet:balance:'.$userId;
    }

    public static function binaryAncestors(int $userId): string
    {
        $prefix = config('mlm.binary.cache_prefix', 'mlm:binary:');

        return $prefix.'ancestors:'.$userId;
    }

    public static function treeNode(int $nodeUserId, string $scope = 'root'): string
    {
        return 'tree:node:'.$scope.':'.$nodeUserId;
    }

    public static function leaderboardMonthly(string $monthKey): string
    {
        return 'leaderboard:pv:'.$monthKey;
    }
}
