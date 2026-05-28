<?php

namespace App\Services;

use App\Models\CommissionEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Cache\MlmCacheInvalidator;
use App\Support\Redis\RedisLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function __construct(
        protected RedisLockService $locks,
        protected MlmCacheInvalidator $cacheInvalidator,
    ) {}

    public function ensureWallet(User $user): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => config('mlm.currency', 'BOB')]
        );
    }

    /**
     * Saldo disponible = créditos + liberaciones − débitos − retenciones activas.
     */
    public function saldoDisponible(User $user): string
    {
        $wallet = $this->ensureWallet($user);

        return $this->formatMoney($this->sumBookBalanceForWalletId((int) $wallet->id));
    }

    public function acreditar(
        User $user,
        string $amount,
        string $idempotencyKey,
        ?CommissionEvent $event = null,
        ?string $reference = null,
        ?string $description = null,
        array $meta = []
    ): ?WalletTransaction {
        if (bccomp($amount, '0', 2) !== 1) {
            return null;
        }

        return $this->locks->walletUser((int) $user->id, function () use ($user, $amount, $idempotencyKey, $event, $reference, $description, $meta) {
            $tx = DB::transaction(function () use ($user, $amount, $idempotencyKey, $event, $reference, $description, $meta) {
                $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }

                $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first()
                    ?? $this->ensureWallet($user);

                $before = $this->bookBalanceBeforeNewRow((int) $wallet->id);
                $after = bcadd($before, $amount, 2);

                $row = new WalletTransaction([
                    'wallet_id' => $wallet->id,
                    'idempotency_key' => $idempotencyKey,
                    'type' => WalletTransaction::TYPE_CREDIT,
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reference' => $reference,
                    'description' => $description,
                    'commission_event_id' => $event?->id,
                    'withdrawal_id' => null,
                    'meta' => $meta,
                    'created_at' => now(),
                ]);
                $row->save();

                return $row;
            });

            $this->cacheInvalidator->onWalletMutation((int) $user->id);

            return $tx;
        });
    }

    public function registrarRetencion(User $user, string $amount, int $withdrawalId, string $idempotencyKey): WalletTransaction
    {
        return $this->locks->walletUser((int) $user->id, function () use ($user, $amount, $withdrawalId, $idempotencyKey) {
            $tx = DB::transaction(function () use ($user, $amount, $withdrawalId, $idempotencyKey) {
                $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }

                $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first()
                    ?? $this->ensureWallet($user);

                $before = $this->bookBalanceBeforeNewRow((int) $wallet->id);
                $after = bcsub($before, $amount, 2);

                $row = new WalletTransaction([
                    'wallet_id' => $wallet->id,
                    'idempotency_key' => $idempotencyKey,
                    'type' => WalletTransaction::TYPE_RETENTION,
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'withdrawal_id' => $withdrawalId,
                    'created_at' => now(),
                ]);
                $row->save();

                return $row;
            });

            $this->cacheInvalidator->onWalletMutation((int) $user->id);

            return $tx;
        });
    }

    public function liberarRetencion(User $user, string $amount, int $withdrawalId, string $idempotencyKey): WalletTransaction
    {
        return $this->locks->walletUser((int) $user->id, function () use ($user, $amount, $withdrawalId, $idempotencyKey) {
            $tx = DB::transaction(function () use ($user, $amount, $withdrawalId, $idempotencyKey) {
                $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }

                $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first()
                    ?? $this->ensureWallet($user);

                $before = $this->bookBalanceBeforeNewRow((int) $wallet->id);
                $after = bcadd($before, $amount, 2);

                $row = new WalletTransaction([
                    'wallet_id' => $wallet->id,
                    'idempotency_key' => $idempotencyKey,
                    'type' => WalletTransaction::TYPE_RETENTION_RELEASE,
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'withdrawal_id' => $withdrawalId,
                    'created_at' => now(),
                ]);
                $row->save();

                return $row;
            });

            $this->cacheInvalidator->onWalletMutation((int) $user->id);

            return $tx;
        });
    }

    public function debitar(User $user, string $amount, string $idempotencyKey, ?int $withdrawalId = null, array $meta = []): WalletTransaction
    {
        return $this->locks->walletUser((int) $user->id, function () use ($user, $amount, $idempotencyKey, $withdrawalId, $meta) {
            $tx = DB::transaction(function () use ($user, $amount, $idempotencyKey, $withdrawalId, $meta) {
                $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }

                $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first()
                    ?? $this->ensureWallet($user);

                $before = $this->bookBalanceBeforeNewRow((int) $wallet->id);
                $after = bcsub($before, $amount, 2);

                $row = new WalletTransaction([
                    'wallet_id' => $wallet->id,
                    'idempotency_key' => $idempotencyKey,
                    'type' => WalletTransaction::TYPE_DEBIT,
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'withdrawal_id' => $withdrawalId,
                    'meta' => $meta,
                    'created_at' => now(),
                ]);
                $row->save();

                return $row;
            });

            $this->cacheInvalidator->onWalletMutation((int) $user->id);

            return $tx;
        });
    }

    public function debitarSiSaldoSuficiente(User $user, string $amount, string $idempotencyKey, array $meta = []): WalletTransaction
    {
        $amount = bcadd((string) $amount, '0', 2);
        if (bccomp($amount, '0', 2) !== 1) {
            throw ValidationException::withMessages(['amount' => ['El monto debe ser mayor a 0.']]);
        }

        return $this->locks->walletUser((int) $user->id, function () use ($user, $amount, $idempotencyKey, $meta) {
            $tx = DB::transaction(function () use ($user, $amount, $idempotencyKey, $meta) {
                $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }

                $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first()
                    ?? $this->ensureWallet($user);

                $before = $this->bookBalanceBeforeNewRow((int) $wallet->id);
                if (bccomp($before, $amount, 2) === -1) {
                    throw ValidationException::withMessages([
                        'wallet' => ['Saldo insuficiente en billetera para completar el pago.'],
                    ]);
                }

                $after = bcsub($before, $amount, 2);

                $row = new WalletTransaction([
                    'wallet_id' => $wallet->id,
                    'idempotency_key' => $idempotencyKey,
                    'type' => WalletTransaction::TYPE_DEBIT,
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'withdrawal_id' => null,
                    'meta' => $meta,
                    'created_at' => now(),
                ]);
                $row->save();

                return $row;
            });

            $this->cacheInvalidator->onWalletMutation((int) $user->id);

            return $tx;
        });
    }

    /**
     * Saldo libro inmediatamente antes de registrar la siguiente fila (con lock en wallet).
     */
    protected function bookBalanceBeforeNewRow(int $walletId): string
    {
        $last = WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($last && $last->balance_after !== null) {
            return $this->formatMoney((string) $last->balance_after);
        }

        return $this->formatMoney($this->sumBookBalanceForWalletId($walletId));
    }

    protected function sumBookBalanceForWalletId(int $walletId): string
    {
        $sum = WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->selectRaw(
                "COALESCE(SUM(CASE
                    WHEN type = ? THEN amount
                    WHEN type = ? THEN amount
                    WHEN type = ? THEN -amount
                    WHEN type = ? THEN -amount
                    ELSE 0 END), 0) as s",
                [
                    WalletTransaction::TYPE_CREDIT,
                    WalletTransaction::TYPE_RETENTION_RELEASE,
                    WalletTransaction::TYPE_DEBIT,
                    WalletTransaction::TYPE_RETENTION,
                ]
            )
            ->value('s');

        return $this->formatMoney((string) $sum);
    }

    private function formatMoney(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
