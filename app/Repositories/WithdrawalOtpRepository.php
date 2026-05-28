<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\WithdrawalOtp;
use Illuminate\Support\Facades\Hash;

class WithdrawalOtpRepository
{
    public function invalidateActiveForUser(int $userId): void
    {
        WithdrawalOtp::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);
    }

    public function create(
        User $user,
        string $plainOtp,
        array $payload,
        ?string $ip,
        ?string $userAgent,
        int $ttlMinutes
    ): WithdrawalOtp {
        $this->invalidateActiveForUser((int) $user->id);

        return WithdrawalOtp::query()->create([
            'user_id' => $user->id,
            'otp_hash' => Hash::make($plainOtp),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'last_sent_at' => now(),
            'ip_address' => $ip ? substr($ip, 0, 45) : null,
            'user_agent' => $userAgent ? substr($userAgent, 0, 512) : null,
            'payload' => $payload,
        ]);
    }

    public function findLatestActiveForUser(int $userId): ?WithdrawalOtp
    {
        return WithdrawalOtp::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    public function markUsed(WithdrawalOtp $otp): void
    {
        $otp->update(['used_at' => now()]);
    }

    public function incrementAttempts(WithdrawalOtp $otp): void
    {
        $otp->increment('attempts');
    }
}
