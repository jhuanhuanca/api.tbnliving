<?php

namespace App\Services;

/**
 * Calculadoras puras (sin BD) para bonos MLM.
 * Retornan resultados estructurados en JSON-array para integrarse con controllers/jobs.
 *
 * Nota: Usamos BCMath para precisión financiera (strings decimales).
 */
class MlmBonusCalculatorService
{
    /**
     * Calcula bono binario diario + resumen semanal con tope y penalización mensual del "acumulado no pagado".
     *
     * @param  array<int, string|int|float>  $leftPV  PV diario acumulado (o del día) pierna izq, indexado por día
     * @param  array<int, string|int|float>  $rightPV PV diario acumulado (o del día) pierna der, indexado por día
     * @return array{
     *   dailyBonus: list<string>,
     *   weeklyBonus: string,
     *   paidWeeklyBonus: string,
     *   accumulated: string,
     *   finalAccumulated: string,
     *   meta: array<string, mixed>
     * }
     */
    public function calcularBonoBinario(array $leftPV, array $rightPV, string $rate = '0.21', string $weeklyCap = '2500', string $monthPenalty = '0.10'): array
    {
        $rate = $this->clampNonNegativeDecimal($rate, 6);
        $weeklyCap = $this->clampNonNegativeDecimal($weeklyCap, 2);
        $monthPenalty = $this->clampBetweenZeroOne($monthPenalty, 6);

        $days = max(count($leftPV), count($rightPV));
        $dailyBonus = [];

        $L = array_values($leftPV);
        $R = array_values($rightPV);

        for ($d = 0; $d < $days; $d++) {
            $lpv = $this->clampNonNegativeDecimal((string) ($L[$d] ?? '0'), 4);
            $rpv = $this->clampNonNegativeDecimal((string) ($R[$d] ?? '0'), 4);

            $dailyMinPV = bccomp($lpv, $rpv, 4) <= 0 ? $lpv : $rpv;
            $bonus = bcmul($dailyMinPV, $rate, 6);
            $dailyBonus[] = bcadd($bonus, '0', 6);

            // carry over del volumen usado (para referencia; no lo devolvemos día a día en este JSON)
            $lpv = bcsub($lpv, $dailyMinPV, 4);
            $rpv = bcsub($rpv, $dailyMinPV, 4);

            if ($d + 1 < $days) {
                $L[$d + 1] = bcadd($lpv, (string) ($L[$d + 1] ?? '0'), 4);
                $R[$d + 1] = bcadd($rpv, (string) ($R[$d + 1] ?? '0'), 4);
            }
        }

        // Bono semanal (suma de 7 días). Si entran más de 7 días, se toma el primer bloque de 7.
        $weeklySum = '0';
        for ($i = 0; $i < min(7, count($dailyBonus)); $i++) {
            $weeklySum = bcadd($weeklySum, (string) $dailyBonus[$i], 6);
        }
        $weeklyBonus = bcadd($weeklySum, '0', 6);

        $paidWeeklyBonus = bccomp($weeklyBonus, $weeklyCap, 6) === 1 ? bcadd($weeklyCap, '0', 6) : $weeklyBonus;
        $accumulated = bcsub($weeklyBonus, $paidWeeklyBonus, 6);
        if (bccomp($accumulated, '0', 6) < 0) {
            $accumulated = '0';
        }

        $factor = bcsub('1', $monthPenalty, 6);
        $finalAccumulated = bcmul($accumulated, $factor, 6);

        return [
            'dailyBonus' => $dailyBonus,
            'weeklyBonus' => bcadd($weeklyBonus, '0', 6),
            'paidWeeklyBonus' => bcadd($paidWeeklyBonus, '0', 6),
            'accumulated' => bcadd($accumulated, '0', 6),
            'finalAccumulated' => bcadd($finalAccumulated, '0', 6),
            'meta' => [
                'rate' => $rate,
                'weeklyCap' => $weeklyCap,
                'monthPenalty' => $monthPenalty,
                'note' => 'Cálculo puro. Integra la persistencia (carry/unpaid) en capa de dominio si se requiere.',
            ],
        ];
    }

    /**
     * Calcula bono de liderazgo por rango en ventana N meses.
     *
     * @param  array<int, string|int|float>  $monthlyTeamPV  PV del equipo por mes (ordenado m=1..N)
     * @return array{leadershipBonus: list<string>, totalLeadershipBonus: string, meta: array<string, mixed>}
     */
    public function calcularBonoLiderazgo(array $monthlyTeamPV, string $requiredPV, string $leadershipRate, int $months = 3): array
    {
        $requiredPV = $this->clampNonNegativeDecimal($requiredPV, 4);
        $leadershipRate = $this->clampBetweenZeroOne($leadershipRate, 6);
        $months = max(1, (int) $months);

        $bonusByMonth = [];
        $total = '0';

        for ($i = 0; $i < $months; $i++) {
            $pv = $this->clampNonNegativeDecimal((string) ($monthlyTeamPV[$i] ?? '0'), 4);
            if (bccomp($pv, $requiredPV, 4) >= 0) {
                $b = bcmul($requiredPV, $leadershipRate, 6);
            } else {
                $b = '0';
            }
            $b = bcadd($b, '0', 6);
            $bonusByMonth[] = $b;
            $total = bcadd($total, $b, 6);
        }

        return [
            'leadershipBonus' => $bonusByMonth,
            'totalLeadershipBonus' => bcadd($total, '0', 6),
            'meta' => [
                'requiredPV' => $requiredPV,
                'leadershipRate' => $leadershipRate,
                'months' => $months,
            ],
        ];
    }

    protected function clampNonNegativeDecimal(string $v, int $scale): string
    {
        $n = trim($v);
        if ($n === '' || ! is_numeric($n)) {
            return bcadd('0', '0', $scale);
        }
        if (str_starts_with($n, '-')) {
            return bcadd('0', '0', $scale);
        }

        return bcadd($n, '0', $scale);
    }

    protected function clampBetweenZeroOne(string $v, int $scale): string
    {
        $n = $this->clampNonNegativeDecimal($v, $scale);
        if (bccomp($n, '1', $scale) === 1) {
            return bcadd('1', '0', $scale);
        }

        return $n;
    }
}

