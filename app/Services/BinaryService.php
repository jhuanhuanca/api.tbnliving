<?php

namespace App\Services;

use App\Models\BinaryLegVolumeWeekly;
use App\Models\BinaryLegVolumeDaily;
use App\Models\BinaryPlacement;
use App\Models\BinaryWeeklyCarry;
use App\Models\Order;
use App\Models\OrderBinaryVolumeApplied;
use App\Models\OrderBinaryVolumeAppliedDaily;
use App\Models\PeriodClosure;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BinaryService
{
    public function weekKey(Carbon $date): string
    {
        return $date->format('o-\WW');
    }

    public function isMonthlyBinaryVolume(): bool
    {
        return config('mlm.binary.volume_period', 'monthly') !== 'weekly';
    }

    /**
     * Periodo de acumulación de volumen binario (clave en tablas binary_leg_volume_weekly / carry / order_binary_volume_applied.week_key).
     */
    public function volumePeriodKey(Carbon $date): string
    {
        if ($this->isMonthlyBinaryVolume()) {
            return $date->format('Y-m');
        }

        return $this->weekKey($date);
    }

    public function binaryPeriodTypeForClosure(): string
    {
        return $this->isMonthlyBinaryVolume() ? 'monthly' : 'weekly';
    }

    public function previousVolumePeriodKey(string $periodKey): string
    {
        if ($this->isMonthlyBinaryVolume() && preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            return Carbon::createFromFormat('Y-m', $periodKey)->subMonth()->format('Y-m');
        }

        return $this->previousWeekKey($periodKey);
    }

    public function previousWeekKey(string $weekKey): string
    {
        if (! preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m)) {
            return $weekKey;
        }

        $d = Carbon::now()->setISODate((int) $m[1], (int) $m[2])->subWeek();

        return $d->format('o-\WW');
    }

    /**
     * @return list<array{user_id:int, leg:string}>
     */
    public function ancestrosBinarioConPierna(int $userId): array
    {
        $ttl = (int) config('mlm.binary.cache_ttl_seconds', 600);
        $prefix = config('mlm.binary.cache_prefix', 'mlm:binary:');

        return Cache::remember("{$prefix}ancestors:{$userId}", $ttl, function () use ($userId) {
            $chain = [];
            $cursor = BinaryPlacement::query()->where('user_id', $userId)->first();

            while ($cursor && $cursor->parent_user_id && $cursor->leg_position) {
                $chain[] = [
                    'user_id' => (int) $cursor->parent_user_id,
                    'leg' => (string) $cursor->leg_position,
                ];
                $cursor = BinaryPlacement::query()->where('user_id', $cursor->parent_user_id)->first();
            }

            return $chain;
        });
    }

    public function olvidarCacheArbol(int $userId): void
    {
        $prefix = config('mlm.binary.cache_prefix', 'mlm:binary:');
        Cache::forget("{$prefix}ancestors:{$userId}");
    }

    /**
     * Idempotente por pedido: suma PV de la orden en la pierna correcta de cada ancestro binario.
     */
    public function acumularVolumenBinarioPorPedido(Order $order): void
    {
        if (OrderBinaryVolumeApplied::query()->where('order_id', $order->id)->exists()) {
            return;
        }

        $order->loadMissing('items');
        $pv = $order->commissionablePvTotal();
        if (bccomp($pv, '0', 2) !== 1) {
            return;
        }

        $periodKey = $order->completed_at
            ? $this->volumePeriodKey(Carbon::parse($order->completed_at))
            : $this->volumePeriodKey(now());

        $dayKey = $order->completed_at
            ? Carbon::parse($order->completed_at)->toDateString()
            : now()->toDateString();

        $chain = $this->ancestrosBinarioConPierna($order->user_id);
        if ($chain === []) {
            return;
        }

        DB::transaction(function () use ($order, $pv, $periodKey, $dayKey, $chain) {
            foreach ($chain as $hop) {
                $this->incrementarVolumenPierna((int) $hop['user_id'], $periodKey, (string) $hop['leg'], $pv);
                $this->incrementarVolumenPiernaDiaria((int) $hop['user_id'], $dayKey, (string) $hop['leg'], $pv);
                $this->olvidarCacheArbol((int) $hop['user_id']);
            }

            OrderBinaryVolumeApplied::query()->create([
                'order_id' => $order->id,
                'week_key' => $periodKey,
                'applied_at' => now(),
            ]);

            // Para binario híbrido diario: idempotencia diaria.
            if (! OrderBinaryVolumeAppliedDaily::query()->where('order_id', $order->id)->exists()) {
                OrderBinaryVolumeAppliedDaily::query()->create([
                    'order_id' => $order->id,
                    'day_key' => $dayKey,
                    'applied_at' => now(),
                ]);
            }
        });

        $this->olvidarCacheArbol($order->user_id);
    }

    public function procesarCierreSemanal(string $periodKey, CommissionService $commissionService): void
    {
        $closureType = $this->binaryPeriodTypeForClosure();
        $closure = PeriodClosure::query()->firstOrCreate(
            [
                'period_type' => $closureType,
                'period_key' => $periodKey,
                'scope' => 'binary',
            ],
            ['status' => 'pending']
        );

        if ($closure->status === 'finished') {
            return;
        }

        $closure->update(['status' => 'running', 'started_at' => now()]);

        $prevKey = $this->previousVolumePeriodKey($periodKey);
        $legacyFlat = (bool) config('mlm.binary.legacy_flat', false);
        $bobPerPv = (string) config('mlm.binary.bob_per_pv', config('mlm.pv_value.bob_per_pv', '9'));
        $binaryRate = (string) config('mlm.binary.matched_pv_commission_rate', '0.21');
        $payoutPerPv = (string) config('mlm.binary.payout_per_matched_pv', '1');

        $parentIdsVol = BinaryLegVolumeWeekly::query()
            ->where('week_key', $periodKey)
            ->distinct()
            ->pluck('parent_user_id');

        $parentIdsCarry = BinaryWeeklyCarry::query()
            ->where('week_key', $prevKey)
            ->pluck('user_id');

        $allParents = $parentIdsVol->merge($parentIdsCarry)->unique()->filter();

        foreach ($allParents as $parentId) {
            $rawL = $this->volumenPierna((int) $parentId, $periodKey, BinaryPlacement::LEG_LEFT);
            $rawR = $this->volumenPierna((int) $parentId, $periodKey, BinaryPlacement::LEG_RIGHT);

            $carry = BinaryWeeklyCarry::query()
                ->where('user_id', $parentId)
                ->where('week_key', $prevKey)
                ->first();

            $carryL = (string) ($carry?->left_carry_pv ?? '0');
            $carryR = (string) ($carry?->right_carry_pv ?? '0');

            $effL = bcadd($carryL, $rawL, 4);
            $effR = bcadd($carryR, $rawR, 4);

            $paidVol = bccomp($effL, $effR, 4) <= 0 ? $effL : $effR;
            $outL = bcsub($effL, $paidVol, 4);
            $outR = bcsub($effR, $paidVol, 4);

            if ($legacyFlat) {
                $payout = bcmul($paidVol, $payoutPerPv, 4);
            } else {
                $payout = bcmul(bcmul($paidVol, $bobPerPv, 4), $binaryRate, 4);
            }
            $payout = bcadd($payout, '0', 2);

            BinaryWeeklyCarry::query()->updateOrCreate(
                [
                    'user_id' => $parentId,
                    'week_key' => $periodKey,
                ],
                [
                    'left_carry_pv' => bcadd($outL, '0', 2),
                    'right_carry_pv' => bcadd($outR, '0', 2),
                ]
            );

            if (bccomp($payout, '0', 2) === 1) {
                $commissionService->calcularBinario(
                    $periodKey,
                    (int) $parentId,
                    bcadd($paidVol, '0', 2),
                    $payout,
                    $closureType
                );
            }
        }

        // PV para rangos/carrera (MLM común):
        // - Proviene del volumen de red binario del periodo (raw left + raw right) por usuario (NO carry).
        // - Se acumula de forma histórica en users.lifetime_qualifying_pv (NO reinicia).
        // - Idempotente por periodo: se marca en PeriodClosure.meta.
        $this->aplicarCareerPvDesdeVolumenBinario($closure, $periodKey);

        $closure->update([
            'status' => 'finished',
            'finished_at' => now(),
            'meta' => array_merge((array) ($closure->meta ?? []), [
                'parents_processed' => $allParents->count(),
                'volume_period' => $closureType,
            ]),
        ]);

        Log::info('MLM cierre binario', ['period' => $periodKey, 'type' => $closureType, 'parents' => $allParents->count()]);
    }

    /**
     * Acumula PV histórico para carrera/rangos basado en volumen binario del periodo.
     *
     * Regla:
     * - group_pv_periodo = left_raw + right_raw (sin carry)
     * - lifetime_qualifying_pv += group_pv_periodo
     */
    protected function aplicarCareerPvDesdeVolumenBinario(PeriodClosure $closure, string $periodKey): void
    {
        $meta = (array) ($closure->meta ?? []);
        if (($meta['career_pv_applied'] ?? false) === true) {
            return;
        }

        // Suma por usuario: volume_pv ya es el raw PV del periodo (acumulado por pedidos).
        $rows = BinaryLegVolumeWeekly::query()
            ->selectRaw('parent_user_id, SUM(volume_pv) as group_pv')
            ->where('week_key', $periodKey)
            ->groupBy('parent_user_id')
            ->get();

        if ($rows->isEmpty()) {
            $closure->update(['meta' => array_merge($meta, ['career_pv_applied' => true, 'career_pv_applied_at' => now()->toIso8601String()])]);
            return;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                $uid = (int) ($r->parent_user_id ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $pv = (string) ($r->group_pv ?? '0');
                // Evita negativos / nulos
                if (! is_numeric($pv) || bccomp($pv, '0', 4) !== 1) {
                    continue;
                }
                // Actualización atómica en DB (evita race conditions).
                DB::statement(
                    'UPDATE users SET lifetime_qualifying_pv = lifetime_qualifying_pv + ? WHERE id = ?',
                    [bcadd($pv, '0', 2), $uid]
                );
            }
        });

        $closure->update([
            'meta' => array_merge($meta, [
                'career_pv_applied' => true,
                'career_pv_applied_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    protected function incrementarVolumenPierna(int $parentUserId, string $periodKey, string $leg, string $pv): void
    {
        $row = BinaryLegVolumeWeekly::query()->firstOrNew([
            'parent_user_id' => $parentUserId,
            'week_key' => $periodKey,
            'leg_position' => $leg,
        ]);

        $row->volume_pv = bcadd((string) ($row->volume_pv ?? '0'), $pv, 2);
        $row->save();
    }

    protected function incrementarVolumenPiernaDiaria(int $parentUserId, string $dayKey, string $leg, string $pv): void
    {
        $row = BinaryLegVolumeDaily::query()->firstOrNew([
            'parent_user_id' => $parentUserId,
            'day_key' => $dayKey,
            'leg_position' => $leg,
        ]);

        $row->volume_pv = bcadd((string) ($row->volume_pv ?? '0'), $pv, 4);
        $row->save();
    }

    protected function volumenPierna(int $parentUserId, string $periodKey, string $leg): string
    {
        $row = BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $parentUserId)
            ->where('week_key', $periodKey)
            ->where('leg_position', $leg)
            ->first();

        return $row ? (string) $row->volume_pv : '0';
    }

    /**
     * Primer hueco libre (izquierda antes que derecha) en amplitud bajo el patrocinador.
     */
    public function placeUserInFirstFreeSlot(User $user): ?BinaryPlacement
    {
        if ($user->binaryPlacement()->exists()) {
            return $user->binaryPlacement;
        }
        if (! $user->sponsor_id) {
            return null;
        }

        $slot = $this->findFirstFreeSlotUnder((int) $user->sponsor_id);
        if ($slot === null) {
            return null;
        }

        return $this->persistPlacement($user, $slot);
    }

    /**
     * Pierna LEFT/RIGHT: recorre la cadena extrema de esa pierna (izq-izq-izq… o der-der-der…)
     * desde el patrocinador hasta el último nivel con hueco válido MLM (un hijo por pierna por padre).
     * Permite múltiples socios “en el mismo lado” en profundidad (no solo un frontal bajo sponsor).
     */
    public function findDeepestOpenSlotOnPreferredLeg(int $sponsorUserId, string $leg): ?array
    {
        if (! in_array($leg, [BinaryPlacement::LEG_LEFT, BinaryPlacement::LEG_RIGHT], true)) {
            return null;
        }

        $current = $sponsorUserId;
        $guard = 0;

        while ($guard < 250_000) {
            $guard++;
            $childUserId = BinaryPlacement::query()
                ->where('parent_user_id', $current)
                ->where('leg_position', $leg)
                ->value('user_id');

            if ($childUserId === null) {
                return ['parent_user_id' => $current, 'leg_position' => $leg];
            }

            $current = (int) $childUserId;
        }

        return null;
    }

    protected function persistPlacement(User $user, array $slot): BinaryPlacement
    {
        $placement = BinaryPlacement::query()->create([
            'user_id' => $user->id,
            'parent_user_id' => $slot['parent_user_id'],
            'leg_position' => $slot['leg_position'],
        ]);

        $this->olvidarCacheArbol($user->id);
        $this->olvidarCacheArbol((int) $slot['parent_user_id']);

        return $placement;
    }

    /** Coloca bajo pierna solicitada usando cadena MLM por profundidad. */
    public function placeUserDirectUnderSponsor(User $user, string $leg): ?BinaryPlacement
    {
        if ($user->binaryPlacement()->exists()) {
            return $user->binaryPlacement;
        }
        if (! $user->sponsor_id) {
            return null;
        }

        $slot = $this->findDeepestOpenSlotOnPreferredLeg((int) $user->sponsor_id, $leg);
        if ($slot === null) {
            return null;
        }

        return $this->persistPlacement($user, $slot);
    }

    /**
     * AUTO: prioriza pierna directa izquierda luego derecha si está vacía ante el sponsor;
     * si ambas ocupadas, delega en BFS (FIFO / hueco cercano primero — ya existente).
     */
    public function placeUserAutoUnderSponsor(User $user): ?BinaryPlacement
    {
        if ($user->binaryPlacement()->exists()) {
            return $user->binaryPlacement;
        }
        if (! $user->sponsor_id) {
            return null;
        }

        $sponsorId = (int) $user->sponsor_id;

        if (! $this->legOccupied($sponsorId, BinaryPlacement::LEG_LEFT)) {
            $slot = $this->findDeepestOpenSlotOnPreferredLeg($sponsorId, BinaryPlacement::LEG_LEFT);
        } elseif (! $this->legOccupied($sponsorId, BinaryPlacement::LEG_RIGHT)) {
            $slot = $this->findDeepestOpenSlotOnPreferredLeg($sponsorId, BinaryPlacement::LEG_RIGHT);
        } else {
            return $this->placeUserInFirstFreeSlot($user);
        }

        if ($slot === null) {
            return $this->placeUserInFirstFreeSlot($user);
        }

        return $this->persistPlacement($user, $slot);
    }

    /**
     * @return array{parent_user_id:int, leg_position:string}|null
     */
    protected function findFirstFreeSlotUnder(int $rootSponsorId): ?array
    {
        $queue = [$rootSponsorId];
        $seen = [];

        while ($queue !== []) {
            $p = array_shift($queue);
            if (isset($seen[$p])) {
                continue;
            }
            $seen[$p] = true;

            if (! $this->legOccupied($p, BinaryPlacement::LEG_LEFT)) {
                return ['parent_user_id' => $p, 'leg_position' => BinaryPlacement::LEG_LEFT];
            }
            if (! $this->legOccupied($p, BinaryPlacement::LEG_RIGHT)) {
                return ['parent_user_id' => $p, 'leg_position' => BinaryPlacement::LEG_RIGHT];
            }

            $leftId = BinaryPlacement::query()
                ->where('parent_user_id', $p)
                ->where('leg_position', BinaryPlacement::LEG_LEFT)
                ->value('user_id');
            $rightId = BinaryPlacement::query()
                ->where('parent_user_id', $p)
                ->where('leg_position', BinaryPlacement::LEG_RIGHT)
                ->value('user_id');
            if ($leftId) {
                $queue[] = (int) $leftId;
            }
            if ($rightId) {
                $queue[] = (int) $rightId;
            }
        }

        return null;
    }

    protected function legOccupied(int $parentUserId, string $leg): bool
    {
        return BinaryPlacement::query()
            ->where('parent_user_id', $parentUserId)
            ->where('leg_position', $leg)
            ->exists();
    }
}
