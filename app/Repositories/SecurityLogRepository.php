<?php

namespace App\Repositories;

use App\Models\SecurityLog;
use App\Models\User;

class SecurityLogRepository
{
    public function log(?User $user, string $action, ?string $ip, ?string $userAgent, array $metadata = []): SecurityLog
    {
        return SecurityLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'ip_address' => $ip ? substr($ip, 0, 45) : null,
            'user_agent' => $userAgent ? substr($userAgent, 0, 512) : null,
            'metadata' => $metadata ?: null,
        ]);
    }
}
