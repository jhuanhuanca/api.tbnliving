<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionEvent;
use App\Models\Order;
use App\Models\PeriodClosure;
use App\Models\User;
use Carbon\Carbon;
use App\Events\Internal\CommissionPaid;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    public function __construct(
        protected WalletService $walletService,
        protected RankService $rankService,
        protected BinaryCapService $binaryCapService,
        protected MonthlyActivationService $monthlyActivationService
    ) {}

    /**
     * BIR: % del schedule sobre PV del paquete → unidades PV de comisión × valor BOB/PV.
     * Alternativa: base commissionable_amount si MLM_BIR_BASE=commissionable_amount.
     */
    public function calcularBonoInicioRapido(Order $order): void
    {
        $buyer = $order->user;
        if (! $buyer?->sponsor_id) {
            return;
        }

        // Regla: bono inicio rápido solo al inscribirse (primer pedido completado del socio).
        $completedOrdersCount = Order::query()
            ->where('user_id', $buyer->id)
            ->where('estado', 'completado')
            ->count();
        if ($completedOrdersCount !== 1) {
            return;
        }

        $schedule = config('mlm.bir.schedule', []);
        $bobPerPv = (string) config('mlm.pv_value.bob_per_pv', '1');
        $baseMode = config('mlm.bir.base', 'pv');

        foreach ($order->items as $item) {
            if (! $item->package_id || ! $item->package) {
                continue;
            }

            if ($baseMode === 'commissionable_amount') {
                $baseUnits = $item->commissionable_amount !== null && $item->commissionable_amount !== ''
                    ? (string) $item->commissionable_amount
                    : bcmul($item->package->commissionableValue(), (string) $item->cantidad, 4);
            } else {
                // PV base = línea completa (commissionable_pv o pv_points del ítem; ya incluye cantidad al crear el pedido).
                $baseUnits = $item->commissionable_pv !== null && $item->commissionable_pv !== ''
                    ? (string) $item->commissionable_pv
                    : (string) $item->pv_points;
            }

            $sponsor = $buyer->sponsor;
            $level = 1;

            while ($sponsor && $level <= 3) {
                $rate = (string) ($schedule[$level] ?? '0');
                $pvComision = bcmul($baseUnits, $rate, 4);
                $amount = $this->roundMoney(bcmul($pvComision, $bobPerPv, 4));

                if (bccomp($amount, '0', 2) === 1) {
                    $key = "bir:order:{$order->id}:pkg:{$item->id}:lvl:{$level}";
                    $this->registrarYAcreditar(
                        idempotencyKey: $key,
                        beneficiary: $sponsor,
                        origin: $buyer,
                        type: 'bir',
                        level: $level,
                        amount: $amount,
                        order: $order,
                        periodKey: $this->weekKey(now()),
                        periodType: 'weekly',
                        meta: [
                            'package_id' => $item->package_id,
                            'order_item_id' => $item->id,
                            'pv_base' => $baseUnits,
                            'pv_comision_nivel' => $pvComision,
                            'bob_per_pv' => $bobPerPv,
                            'commissionable_pv' => $item->commissionable_pv,
                            'commissionable_amount' => $item->commissionable_amount,
                            'fx_rate_to_wallet' => $item->fx_rate_to_wallet,
                        ]
                    );
                }

                $sponsor = $sponsor->sponsor;
                $level++;
            }
        }
    }

    /**
     * Punto único de entrada para acreditaciones por pedido (BIR + residual).
     */
    public function procesarAcreditacionesPorPedido(Order $order): void
    {
        $this->calcularBonoInicioRapido($order);
        $this->calcularResidualPorPedido($order);
    }

    /**
     * Residual: matriz por rango efectivo × % por generación, con compresión dinámica.
     * Solo ascendientes con activación mensual (PV ≥ umbral) pueden cobrar; si no, el porcentaje
     * de esa generación se asigna al siguiente ascendente calificado (misma lógica de “generación comprimida”).
     */
    public function calcularResidualPorPedido(Order $order): void
    {
        $buyer = $order->user;
        if (! $buyer?->sponsor_id) {
            return;
        }

        // Regla negocio: NO hay residual por la inscripción/paquete inicial.
        // El residual empieza desde la 2.ª compra completada del socio (incluyendo esa 2.ª compra).
        $completedOrdersCount = Order::query()
            ->where('user_id', $buyer->id)
            ->where('estado', 'completado')
            ->count();
        if ($completedOrdersCount < 2) {
            return;
        }

        $pv = $this->roundMoney($order->commissionablePvTotal());
        if (bccomp($pv, '0', 2) !== 1) {
            return;
        }

        $bobPerPv = (string) config('mlm.pv_value.bob_per_pv', '7');

        $chain = $this->cadenaPatrocinadoresConRango((int) $buyer->id, 12);
        $compressed = [];
        foreach ($chain as $sponsor) {
            if ($this->puedeCobrarBonoPorActivacionMensual($sponsor)) {
                $compressed[] = $sponsor;
            }
        }

        $cGen = 1;
        foreach ($compressed as $sponsor) {
            if ($cGen > 12) {
                break;
            }

            $slug = $this->rankService->slugEfectivoParaResidual($sponsor);
            $rates = $this->residualRatesForRankSlug($slug);
            $rate = (string) ($rates[$cGen] ?? '0');

            if (bccomp($rate, '0', 6) === 1) {
                $pvComision = bcmul($pv, $rate, 4);
                $amount = $this->roundMoney(bcmul($pvComision, $bobPerPv, 4));
                if (bccomp($amount, '0', 2) === 1) {
                    $key = "residual:order:{$order->id}:cg:{$cGen}:u:{$sponsor->id}";
                    $this->registrarYAcreditar(
                        idempotencyKey: $key,
                        beneficiary: $sponsor,
                        origin: $buyer,
                        type: 'residual',
                        level: $cGen,
                        amount: $amount,
                        order: $order,
                        periodKey: now()->format('Y-m'),
                        periodType: 'monthly',
                        meta: [
                            'commissionable_pv' => $pv,
                            'effective_rank_slug' => $slug,
                            'rate' => $rate,
                            'pv_comision' => $pvComision,
                            'bob_per_pv' => $bobPerPv,
                            'buyer_completed_orders_count' => $completedOrdersCount,
                            'residual_dynamic_compression' => true,
                            'residual_compressed_generation' => $cGen,
                        ]
                    );
                }
            }

            $cGen++;
        }
    }

    /**
     * Activación mensual: PV de paquetes personales del mes ≥ umbral según rango (50 o 100).
     */
    public function puedeCobrarBonoPorActivacionMensual(User $user): bool
    {
        return $this->monthlyActivationService->isQualified($user);
    }

    /**
     * Acredita en billetera las comisiones diferidas (BIR, residual, venta directa, liderazgo)
     * correspondientes a una semana ISO ya cerrada (domingo job → semana anterior).
     */
    public function acreditarPendientesPorSemanaIso(string $weekKey): int
    {
        $paid = 0;

        Commission::query()
            ->where('status', 'pending_wallet')
            ->whereHas('event', function ($q) use ($weekKey) {
                $q->where('accrual_week_key', $weekKey)->whereNull('wallet_credited_at');
            })
            ->with(['event' => fn ($q) => $q->with('beneficiary')])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$paid) {
                foreach ($rows as $commission) {
                    $event = $commission->event;
                    if (! $event) {
                        continue;
                    }
                    $beneficiary = $event->beneficiary ?? User::query()->find($event->beneficiary_user_id);
                    if (! $beneficiary) {
                        continue;
                    }

                    DB::transaction(function () use ($commission, $event, $beneficiary, &$paid) {
                        $fresh = CommissionEvent::query()->whereKey($event->id)->lockForUpdate()->first();
                        if (! $fresh || $fresh->wallet_credited_at !== null) {
                            return;
                        }

                        $walletKey = 'wallet:credit:'.$fresh->idempotency_key;
                        $walletDescription = match ($fresh->type) {
                            'venta_directa' => 'Bono venta directa (cliente preferente)',
                            default => "Comisión {$fresh->type}",
                        };

                        $meta = is_array($fresh->meta) ? $fresh->meta : [];
                        $tx = $this->walletService->acreditar(
                            $beneficiary,
                            (string) $fresh->amount,
                            $walletKey,
                            $fresh,
                            reference: $fresh->type,
                            description: $walletDescription,
                            meta: array_merge($meta, ['commission_event_id' => $fresh->id])
                        );

                        $fresh->forceFill(['wallet_credited_at' => now()])->save();

                        Commission::query()->whereKey($commission->id)->update([
                            'status' => 'accrued',
                            'wallet_transaction_id' => $tx?->id,
                        ]);
                        $paid++;
                    });
                }
            });

        return $paid;
    }

    /**
     * Cadena de patrocinio hasta 12 niveles (CTE recursivo o recorrido según motor BD).
     *
     * @return list<User>
     */
    protected function cadenaPatrocinadoresConRango(int $buyerId, int $maxDepth): array
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            $sql = '
                WITH RECURSIVE sponsor_chain AS (
                    SELECT u.id, u.sponsor_id, 1 AS gen
                    FROM users u
                    WHERE u.id = (SELECT sponsor_id FROM users WHERE id = ?)
                    UNION ALL
                    SELECT u2.id, u2.sponsor_id, sc.gen + 1
                    FROM sponsor_chain sc
                    INNER JOIN users u2 ON u2.id = sc.sponsor_id
                    WHERE sc.gen < ? AND sc.sponsor_id IS NOT NULL
                )
                SELECT id, gen FROM sponsor_chain
            ';

            $rows = DB::select($sql, [$buyerId, $maxDepth]);
            $ids = array_map(fn ($r) => (int) $r->id, $rows);
            if ($ids === []) {
                return [];
            }

            $users = User::query()->with('rank')->whereIn('id', $ids)->get()->keyBy('id');
            $ordered = [];
            foreach ($rows as $r) {
                $u = $users->get((int) $r->id);
                if ($u) {
                    $ordered[] = $u;
                }
            }

            return $ordered;
        }

        return $this->cadenaPatrocinadoresFallback($buyerId, $maxDepth);
    }

    /**
     * @return list<User>
     */
    protected function cadenaPatrocinadoresFallback(int $buyerId, int $maxDepth): array
    {
        $ids = [];
        $nextId = User::query()->whereKey($buyerId)->value('sponsor_id');
        $guard = 0;

        while ($nextId && count($ids) < $maxDepth && $guard < 32) {
            $guard++;
            $ids[] = (int) $nextId;
            $nextId = User::query()->whereKey($nextId)->value('sponsor_id');
        }

        if ($ids === []) {
            return [];
        }

        $order = array_flip($ids);

        return User::query()->with('rank')->whereIn('id', $ids)->get()->sortBy(fn (User $u) => $order[(int) $u->id] ?? 999)->values()->all();
    }

    /**
     * @return array<int, float|int|string>
     */
    protected function residualRatesForRankSlug(string $slug): array
    {
        $matrix = config('mlm.residual.matrix_by_rank_slug', []);
        if ($slug !== 'default' && $slug !== '' && isset($matrix[$slug]) && is_array($matrix[$slug])) {
            return $matrix[$slug];
        }

        return config('mlm.residual.default_generations', []);
    }

    /**
     * Binario: paga volumen emparejado en pierna débil; carry según periodo (semana ISO o mes Y-m).
     */
    public function calcularBinario(
        string $periodKey,
        int $userId,
        string $matchedPv,
        string $payoutAmount,
        string $periodType = 'weekly'
    ): void {
        $user = User::query()->find($userId);
        if (! $user || bccomp($payoutAmount, '0', 2) !== 1) {
            return;
        }

        $cap = $this->binaryCapService->aplicarTopePagoBinario($userId, $payoutAmount, $periodKey);
        $finalPayout = $cap['amount'];
        if (bccomp($finalPayout, '0', 2) !== 1) {
            return;
        }

        // Nota: para binario híbrido diario, el pago semanal se sigue registrando como type=binary por semana,
        // pero la fuente del cálculo proviene de binary_daily_payouts/binary_weekly_bonus.
        $key = "binary:{$userId}:{$periodKey}";
        $meta = array_merge([
            'matched_pv' => $matchedPv,
            'legacy_flat' => (bool) config('mlm.binary.legacy_flat', false),
            'bob_per_pv' => config('mlm.binary.bob_per_pv'),
            'matched_rate' => config('mlm.binary.matched_pv_commission_rate'),
            'binary_volume_period' => $periodType,
        ], $cap['meta']);

        $this->registrarYAcreditar(
            idempotencyKey: $key,
            beneficiary: $user,
            origin: null,
            type: 'binary',
            level: null,
            amount: $finalPayout,
            order: null,
            periodKey: $periodKey,
            periodType: $periodType,
            meta: $meta
        );
    }

    /**
     * Bono de liderazgo: extensible; aplica tasa del rango del beneficiario sobre comisiones de downline (placeholder seguro).
     */
    public function calcularLiderazgo(
        User $beneficiary,
        string $monthKey,
        string $baseAmount,
        ?string $rateOverride = null,
        ?string $rankSlugForMeta = null
    ): void
    {
        // baseAmount: PV requerido/base PV según la fórmula de liderazgo solicitada.
        $rate = $rateOverride !== null ? (string) $rateOverride : (string) ($beneficiary->rank?->leadership_rate ?? config('mlm.leadership.default_rate', 0));
        if (bccomp($rate, '0', 6) !== 1) {
            return;
        }

        $bobPerPv = (string) config('mlm.pv_value.bob_per_pv', '7');
        $pvComision = bcadd(bcmul($baseAmount, $rate, 6), '0', 6);
        $amount = $this->roundMoney(bcmul($pvComision, $bobPerPv, 4));
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $key = "leadership:u:{$beneficiary->id}:{$monthKey}";
        $this->registrarYAcreditar(
            idempotencyKey: $key,
            beneficiary: $beneficiary,
            origin: null,
            type: 'leadership',
            level: null,
            amount: $amount,
            order: null,
            periodKey: $monthKey,
            periodType: 'monthly',
            meta: [
                'required_pv' => $baseAmount,
                'rate' => $rate,
                'pv_comision' => $pvComision,
                'bob_per_pv' => $bobPerPv,
                'rank_slug' => $rankSlugForMeta,
            ]
        );
    }

    /**
     * Bono venta directa: 10% del precio público × cantidad → wallet del patrocinador.
     * Solo pedidos de compradores con account_type = preferred_customer e ítems de producto.
     */
    public function acreditarBonosVentaDirectaPreferente(Order $order): void
    {
        $buyer = $order->user;
        if (! $buyer || ! $buyer->isPreferredCustomer() || ! $buyer->sponsor_id) {
            return;
        }

        $sponsor = User::query()->find($buyer->sponsor_id);
        if (! $sponsor) {
            return;
        }

        $order->loadMissing(['items.product']);

        foreach ($order->items as $item) {
            if (! $item->product_id || ! $item->product) {
                continue;
            }

            $prod = $item->product;
            $socioUnit = bcadd((string) $prod->price, '0', 2);
            $publicUnit = \App\Support\PreferredCustomerPricing::publicPrice($prod);
            $paidUnit = bcadd((string) $item->precio_unitario, '0', 2);
            $qty = (string) $item->cantidad;
            $commissionUnit = \App\Support\PreferredCustomerPricing::sponsorCommissionUnit($prod);
            if (bccomp($commissionUnit, '0', 2) !== 1) {
                continue;
            }

            $amount = $this->roundMoney(bcmul($commissionUnit, $qty, 4));
            if (bccomp($amount, '0', 2) !== 1) {
                continue;
            }

            $key = "venta_directa:order:{$order->id}:item:{$item->id}";
            $this->registrarYAcreditar(
                idempotencyKey: $key,
                beneficiary: $sponsor,
                origin: $buyer,
                type: 'venta_directa',
                level: null,
                amount: $amount,
                order: $order,
                periodKey: now()->format('Y-m'),
                periodType: 'monthly',
                meta: [
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'precio_socio_unit' => $socioUnit,
                    'precio_publico_unit' => $publicUnit,
                    'precio_cliente_unit' => $paidUnit,
                    'cantidad' => $qty,
                    'commission_rate' => \App\Support\PreferredCustomerPricing::SPONSOR_COMMISSION_RATE,
                    'commission_unit' => $commissionUnit,
                    'label' => 'Bono venta directa',
                ]
            );
        }
    }

    public function procesarCierreMensual(string $monthKey): void
    {
        $closure = PeriodClosure::query()->firstOrCreate(
            [
                'period_type' => 'monthly',
                'period_key' => $monthKey,
                'scope' => 'residual_audit',
            ],
            ['status' => 'pending']
        );

        if ($closure->status === 'finished') {
            return;
        }

        $closure->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        Log::info('MLM cierre mensual ejecutado (auditoría / extensión)', ['month' => $monthKey]);

        $closure->update([
            'status' => 'finished',
            'finished_at' => now(),
            'meta' => ['note' => 'Auditoría mensual. BIR/residual/venta directa/liderazgo: evento al pedido; billetera en cierre semanal ISO.'],
        ]);
    }

    /**
     * BIR, residual, venta directa y liderazgo: evento + comisión al instante;
     * acreditación en billetera al cierre semanal ISO (como el flujo del binario).
     * El tipo `binary` se acredita de inmediato (flujo existente del cierre binario).
     */
    protected function shouldDeferWalletForType(string $type): bool
    {
        if (! (bool) config('mlm.commissions.defer_non_binary_to_weekly_close', true)) {
            return false;
        }

        return in_array($type, ['bir', 'residual', 'venta_directa', 'leadership'], true);
    }

    protected function accrualWeekKeyForContext(?Order $order): string
    {
        if ($order && $order->completed_at) {
            return Carbon::parse($order->completed_at)->format('o-\WW');
        }

        return now()->format('o-\WW');
    }

    protected function registrarYAcreditar(
        string $idempotencyKey,
        User $beneficiary,
        ?User $origin,
        string $type,
        ?int $level,
        string $amount,
        ?Order $order,
        ?string $periodKey,
        ?string $periodType,
        array $meta = []
    ): void {
        DB::transaction(function () use ($idempotencyKey, $beneficiary, $origin, $type, $level, $amount, $order, $periodKey, $periodType, $meta) {
            $uniqueHash = hash('sha256', $idempotencyKey);
            $deferWallet = false;
            $event = null;

            try {
                $deferWallet = $this->shouldDeferWalletForType($type);
                $weekKey = $this->accrualWeekKeyForContext($order);

                $event = CommissionEvent::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'unique_hash' => $uniqueHash,
                    'beneficiary_user_id' => $beneficiary->id,
                    'origin_user_id' => $origin?->id,
                    'type' => $type,
                    'level' => $level,
                    'amount' => $amount,
                    'currency' => config('mlm.currency', 'BOB'),
                    'period_key' => $periodKey,
                    'period_type' => $periodType,
                    'accrual_week_key' => $weekKey,
                    'order_id' => $order?->id,
                    'meta' => array_merge($meta, [
                        'payout_deferred_to_weekly_close' => $deferWallet,
                    ]),
                    'wallet_credited_at' => null,
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return;
            }

            if (! $event) {
                return;
            }

            if ($deferWallet) {
                Commission::query()->create([
                    'commission_event_id' => $event->id,
                    'user_id' => $beneficiary->id,
                    'type' => $type,
                    'level' => $level,
                    'amount' => $amount,
                    'status' => 'pending_wallet',
                    'wallet_transaction_id' => null,
                ]);

                return;
            }

            $walletKey = 'wallet:credit:'.$idempotencyKey;
            $walletDescription = match ($type) {
                'venta_directa' => 'Bono venta directa (cliente preferente)',
                default => "Comisión {$type}",
            };

            $tx = $this->walletService->acreditar(
                $beneficiary,
                $amount,
                $walletKey,
                $event,
                reference: $type,
                description: $walletDescription,
                meta: array_merge($meta, ['commission_event_id' => $event->id])
            );

            $event->forceFill(['wallet_credited_at' => now()])->save();

            CommissionPaid::dispatch($event->fresh());

            Commission::query()->create([
                'commission_event_id' => $event->id,
                'user_id' => $beneficiary->id,
                'type' => $type,
                'level' => $level,
                'amount' => $amount,
                'status' => 'accrued',
                'wallet_transaction_id' => $tx?->id,
            ]);
        });
    }

    protected function weekKey(Carbon $date): string
    {
        return $date->format('o-\WW');
    }

    protected function roundMoney(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
