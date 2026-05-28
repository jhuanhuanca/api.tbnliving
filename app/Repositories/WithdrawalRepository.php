<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WithdrawalRepository
{
    public function paginateForUser(User $user, int $perPage = 25): LengthAwarePaginator
    {
        return Withdrawal::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function createFromOtpSession(
        User $user,
        string $amount,
        string $fee,
        string $netAmount,
        ?string $notes,
        int $otpId,
        ?string $ip,
        ?string $device,
        string $idempotencyKey
    ): Withdrawal {
        return Withdrawal::query()->create([
            'user_id' => $user->id,
            'withdrawal_otp_id' => $otpId,
            'monto' => $amount,
            'fee' => $fee,
            'net_amount' => $netAmount,
            'estado' => Withdrawal::ESTADO_PENDIENTE,
            'notas_usuario' => $notes,
            'idempotency_key' => $idempotencyKey,
            'created_ip' => $ip ? substr($ip, 0, 45) : null,
            'created_device' => $device ? substr($device, 0, 512) : null,
        ]);
    }
}
