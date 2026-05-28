<?php

namespace App\Services;

use App\Models\BinaryDailyCarry;
use App\Models\BinaryDailyPayout;
use App\Models\BinaryLegVolumeDaily;
use App\Models\BinaryPlacement;
use App\Models\BinaryWeeklyBonus;
use App\Models\CommissionEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Implementación B: binario híbrido diario:
 * - Día: matchedPV = min(leftEff, rightEff); dailyBonus = matchedPV * rate.
 * - Semana (7 días): weeklyBonus = sum(dailyBonus); paidWeeklyBonus = min(weeklyBonus, weeklyCap).
 * - accumulated = weeklyBonus - paidWeeklyBonus.
 * - Mes: finalAccumulated = accumulated * (1 - monthPenalty).
 *
 * Nota: En producción conviene ejecutar el job diario a medianoche para el día anterior,
 * el semanal al cierre de semana ISO, y el mensual al inicio del mes siguiente.
 */
class BinaryHybridDailyService
{
    public function __construct(
        protected CommissionService $commissionService
    ) {}

    public function enabled(): bool
    {
        return (bool) config('mlm.binary.hybrid_daily.enabled', true);
    }

    public function processDaily(string $dayKey): void
    {
        if (! $this->enabled()) {
            return;
        }

        $rate = bcadd((string) config('mlm.binary.hybrid_daily.rate', '0.21'), '0', 6);
        if (bccomp($rate, '0', 6) !== 1) {
            return;
        }

        $bobPerPv = bcadd((string) config('mlm.binary.bob_per_pv', config('mlm.pv_value.bob_per_pv', '7')), '0', 6);

        $day = Carbon::parse($dayKey)->toDateString();
        $nextDay = Carbon::parse($day)->addDay()->toDateString();

        // Usuarios a procesar: los que tuvieron volumen o carry en el día.
        $parentsVol = BinaryLegVolumeDaily::query()
            ->where('day_key', $day)
            ->distinct()
            ->pluck('parent_user_id');

        $parentsCarry = BinaryDailyCarry::query()
            ->where('day_key', $day)
            ->pluck('user_id');

        $userIds = $parentsVol->merge($parentsCarry)->unique()->filter();

        foreach ($userIds as $userId) {
            $uid = (int) $userId;
            $L = (string) BinaryLegVolumeDaily::query()
                ->where('parent_user_id', $uid)
                ->where('day_key', $day)
                ->where('leg_position', BinaryPlacement::LEG_LEFT)
                ->value('volume_pv') ?? '0';
            $R = (string) BinaryLegVolumeDaily::query()
                ->where('parent_user_id', $uid)
                ->where('day_key', $day)
                ->where('leg_position', BinaryPlacement::LEG_RIGHT)
                ->value('volume_pv') ?? '0';

            $carry = BinaryDailyCarry::query()
                ->where('user_id', $uid)
                ->where('day_key', $day)
                ->first();

            $carryL = (string) ($carry?->left_carry_pv ?? '0');
            $carryR = (string) ($carry?->right_carry_pv ?? '0');

            // Validación no negativos
            $L = $this->nn($L, 4);
            $R = $this->nn($R, 4);
            $carryL = $this->nn($carryL, 4);
            $carryR = $this->nn($carryR, 4);

            $effL = bcadd($carryL, $L, 4);
            $effR = bcadd($carryR, $R, 4);

            $matched = bccomp($effL, $effR, 4) <= 0 ? $effL : $effR;
            $outL = bcsub($effL, $matched, 4);
            $outR = bcsub($effR, $matched, 4);

            if (bccomp($matched, '0', 4) < 0) {
                $matched = '0';
            }
            if (bccomp($outL, '0', 4) < 0) {
                $outL = '0';
            }
            if (bccomp($outR, '0', 4) < 0) {
                $outR = '0';
            }

            $dailyBonusPv = bcmul($matched, $rate, 6);
            $dailyBonusBob = bcmul($dailyBonusPv, $bobPerPv, 6);

            DB::transaction(function () use ($uid, $day, $effL, $effR, $matched, $dailyBonusPv, $dailyBonusBob, $nextDay, $outL, $outR, $rate, $bobPerPv) {
                BinaryDailyPayout::query()->updateOrCreate(
                    ['user_id' => $uid, 'day_key' => $day],
                    [
                        'left_eff_pv' => $effL,
                        'right_eff_pv' => $effR,
                        'matched_pv' => $matched,
                        'daily_bonus_pv' => bcadd($dailyBonusPv, '0', 6),
                        'daily_bonus_bob' => bcadd($dailyBonusBob, '0', 6),
                        'meta' => [
                            'rate' => bcadd($rate, '0', 6),
                            'bob_per_pv' => bcadd($bobPerPv, '0', 6),
                        ],
                    ]
                );

                // Guardar carry-in del siguiente día (carry-out de hoy).
                BinaryDailyCarry::query()->updateOrCreate(
                    ['user_id' => $uid, 'day_key' => $nextDay],
                    [
                        'left_carry_pv' => bcadd($outL, '0', 4),
                        'right_carry_pv' => bcadd($outR, '0', 4),
                    ]
                );
            });
        }
    }

    public function processWeekly(string $weekKey): void
    {
        if (! $this->enabled()) {
            return;
        }

        $capBob = $this->weeklyCapBob();
        $monthKey = $this->monthKeyFromIsoWeek($weekKey);

        $days = $this->daysOfIsoWeek($weekKey); // 7 day_keys

        // Asegurar consistencia del carry: reconstruir (o recalcular) los 7 días en orden.
        // Esto evita que un cierre semanal encuentre daily_payouts sin carry correcto
        // (por ejemplo, cuando se ejecuta por primera vez o tras backfill).
        foreach ($days as $dayKey) {
            $this->processDaily($dayKey);
        }

        $userIds = BinaryDailyPayout::query()
            ->whereIn('day_key', $days)
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $uid) {
            $userId = (int) $uid;

            $weeklyBonus = (string) BinaryDailyPayout::query()
                ->where('user_id', $userId)
                ->whereIn('day_key', $days)
                ->sum('daily_bonus_bob');
            $weeklyBonus = bcadd($weeklyBonus, '0', 6);

            $paid = bccomp($weeklyBonus, $capBob, 6) === 1 ? $capBob : $weeklyBonus;
            $accumulated = bcsub($weeklyBonus, $paid, 6);
            if (bccomp($accumulated, '0', 6) < 0) {
                $accumulated = '0';
            }

            // Registrar pago semanal como comisión binaria (si hay algo que pagar)
            if (bccomp($paid, '0', 6) === 1) {
                $user = User::query()->find($userId);
                if ($user) {
                    $this->commissionService->calcularBinario(
                        $weekKey,
                        $userId,
                        '0.00',
                        bcadd($paid, '0', 2),
                        'weekly'
                    );
                    // Nota: calcularBinario crea CommissionEvent con idempotencyKey "binary:user:period".
                    // Para híbrido, dejamos un resumen adicional abajo.
                }
            }

            BinaryWeeklyBonus::query()->updateOrCreate(
                ['user_id' => $userId, 'week_key' => $weekKey],
                [
                    'month_key' => $monthKey,
                    'weekly_bonus_bob' => $weeklyBonus,
                    'paid_weekly_bonus_bob' => $paid,
                    'accumulated_unpaid_bob' => $accumulated,
                    'final_accumulated_bob' => $accumulated, // se ajusta en penalización mensual
                    'month_penalty_applied' => false,
                    'meta' => [
                        'days' => $days,
                        'cap_bob' => $capBob,
                    ],
                ]
            );
        }
    }

    public function applyMonthlyPenalty(string $monthKey): void
    {
        if (! $this->enabled()) {
            return;
        }

        $penalty = bcadd((string) config('mlm.binary.hybrid_daily.month_penalty', '0.10'), '0', 6);
        if (bccomp($penalty, '0', 6) < 0) {
            $penalty = '0';
        }
        if (bccomp($penalty, '1', 6) === 1) {
            $penalty = '1';
        }
        $factor = bcsub('1', $penalty, 6);

        $rows = BinaryWeeklyBonus::query()
            ->where('month_key', $monthKey)
            ->where('month_penalty_applied', false)
            ->get();

        foreach ($rows as $row) {
            $acc = bcadd((string) $row->accumulated_unpaid_bob, '0', 6);
            $final = bcmul($acc, $factor, 6);
            $row->forceFill([
                'final_accumulated_bob' => bcadd($final, '0', 6),
                'month_penalty_applied' => true,
                'meta' => array_merge($row->meta ?? [], [
                    'month_penalty' => $penalty,
                    'factor' => $factor,
                ]),
            ])->save();
        }
    }

    /**
     * @return list<string> YYYY-MM-DD (lunes..domingo)
     */
    protected function daysOfIsoWeek(string $weekKey): array
    {
        if (! preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m)) {
            return [];
        }
        $y = (int) $m[1];
        $w = (int) $m[2];
        $start = Carbon::now()->setISODate($y, $w, 1)->startOfDay();
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $out[] = $start->copy()->addDays($i)->toDateString();
        }
        return $out;
    }

    protected function monthKeyFromIsoWeek(string $weekKey): string
    {
        $days = $this->daysOfIsoWeek($weekKey);
        if ($days === []) {
            return now()->format('Y-m');
        }
        // Mes del lunes de la semana ISO.
        return Carbon::parse($days[0])->format('Y-m');
    }

    protected function nn(string $v, int $scale): string
    {
        $n = is_numeric($v) ? bcadd($v, '0', $scale) : bcadd('0', '0', $scale);
        return bccomp($n, '0', $scale) === -1 ? bcadd('0', '0', $scale) : $n;
    }

    protected function weeklyCapBob(): string
    {
        $direct = (string) config('mlm.binary.hybrid_daily.weekly_cap_bob', '');
        $direct = trim($direct);
        if ($direct !== '' && is_numeric($direct)) {
            $cap = bcadd($direct, '0', 6);
            return bccomp($cap, '0', 6) === -1 ? bcadd('0', '0', 6) : $cap;
        }

        $usd = (string) config('mlm.binary.hybrid_daily.weekly_cap_usd', '2500');
        $usd = $this->nn($usd, 6);
        $bobPerUsd = (string) config('mlm.auto_okm.bob_per_usd', '7');
        $bobPerUsd = $this->nn($bobPerUsd, 6);

        return bcmul($usd, $bobPerUsd, 6);
    }
}

