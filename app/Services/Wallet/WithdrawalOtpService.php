<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WithdrawalOtp;
use App\Repositories\WithdrawalOtpRepository;
use Illuminate\Support\Facades\Hash;

class WithdrawalOtpService
{
    public function __construct(
        protected WithdrawalOtpRepository $repository
    ) {}

    public function ttlMinutes(): int
    {
        return max(1, (int) config('mlm.withdrawals.otp_ttl_minutes', 5));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('mlm.withdrawals.otp_max_attempts', 3));
    }

    public function resendCooldownSeconds(): int
    {
        return max(30, (int) config('mlm.withdrawals.otp_resend_cooldown_seconds', 60));
    }

    public function generatePlainCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function issue(
        User $user,
        string $plainOtp,
        array $payload,
        ?string $ip,
        ?string $userAgent
    ): WithdrawalOtp {
        return $this->repository->create(
            $user,
            $plainOtp,
            $payload,
            $ip,
            $userAgent,
            $this->ttlMinutes()
        );
    }

    public function findActiveForUser(User $user): ?WithdrawalOtp
    {
        return $this->repository->findLatestActiveForUser((int) $user->id);
    }

    public function verify(User $user, string $plainOtp): ?WithdrawalOtp
    {
        $otp = $this->findActiveForUser($user);
        if (! $otp) {
            return null;
        }

        if ($otp->attempts >= $this->maxAttempts()) {
            $this->repository->markUsed($otp);

            return null;
        }

        if (! Hash::check(trim($plainOtp), $otp->otp_hash)) {
            $this->repository->incrementAttempts($otp);
            $otp->refresh();
            if ($otp->attempts >= $this->maxAttempts()) {
                $this->repository->markUsed($otp);
            }

            return null;
        }

        return $otp;
    }

    public function secondsUntilResendAllowed(?WithdrawalOtp $otp): int
    {
        if (! $otp || ! $otp->last_sent_at) {
            return 0;
        }
        $elapsed = now()->diffInSeconds($otp->last_sent_at, false);
        $cooldown = $this->resendCooldownSeconds();
        $remaining = $cooldown - abs((int) $elapsed);

        return max(0, $remaining);
    }

    public function markUsed(WithdrawalOtp $otp): void
    {
        $this->repository->markUsed($otp);
    }
}
