<?php

namespace App\Services;

use App\Models\BinaryLegVolumeWeekly;
use App\Models\CommissionEvent;
use App\Models\Order;
use App\Models\Rank;
use App\Models\User;
use Carbon\Carbon;

/**
 * Estadísticas de avance hacia bonos (binario, rango residual, ciclo de calificación, Auto OKM, onboarding Plata).
 */
class MlmBonusProgressService
{
    public function __construct(
        protected BinaryService $binaryService,
        protected CareerRankService $careerRankService
    ) {}

    /**
     * Resumen para panel: pierna débil, % hacia siguiente umbral de rango por PV, ciclo 1–27, barras, onboarding.
     */
    public function resumen(User $user): array
    {
        $user->loadMissing('rank');

        $cycle = config('mlm.qualification_cycle', ['start_day' => 1, 'end_day' => 27]);
        $startD = (int) ($cycle['start_day'] ?? 1);
        $endD = (int) ($cycle['end_day'] ?? 27);
        $now = Carbon::now();
        $day = (int) $now->day;
        if ($day > $endD || $day < $startD) {
            $daysLeftInCycle = 0;
        } else {
            $daysLeftInCycle = max(0, $endD - $day);
        }

        $periodKey = $this->binaryService->volumePeriodKey($now);
        $leftPv = (string) BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $user->id)
            ->where('week_key', $periodKey)
            ->where('leg_position', 'left')
            ->value('volume_pv') ?? '0';
        $rightPv = (string) BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $user->id)
            ->where('week_key', $periodKey)
            ->where('leg_position', 'right')
            ->value('volume_pv') ?? '0';

        $weak = bccomp($leftPv, $rightPv, 2) <= 0 ? 'left' : 'right';
        $weakVal = $weak === 'left' ? $leftPv : $rightPv;
        $strongVal = $weak === 'left' ? $rightPv : $leftPv;
        $matchable = bccomp($leftPv, $rightPv, 2) <= 0 ? $leftPv : $rightPv;

        $careerStep = $this->careerRankService->describeNextCareerStep($user->loadMissing('registrationPackage', 'referrals.rank', 'rank'));
        $rankInfo = [
            'current_pv' => $careerStep['current_group_pv_light'],
            'next_slug' => $careerStep['next_slug'],
            'next_threshold_pv' => $careerStep['next_min_group_pv'],
            'percent' => $careerStep['percent_pv'],
            'missing_messages' => $careerStep['missing_messages'],
            'at_career_cap' => $careerStep['at_career_cap'],
            'pv_remaining_to_next' => $this->pvFaltantesSiguienteRango($careerStep['current_group_pv_light'], [
                'next_threshold_pv' => $careerStep['next_min_group_pv'],
            ]),
        ];

        $directosActivos = User::query()
            ->where('sponsor_id', $user->id)
            ->where('account_status', 'active')
            ->where('is_mlm_qualified', true)
            ->count();

        $rankAscentBar = $this->rankAscentBar($rankInfo);
        $autoOkmBar = $this->autoOkmProgress($user);
        $plataOnboarding = $this->plataOnboardingPayload($user);

        return [
            'qualification_cycle' => [
                'start_day' => $startD,
                'end_day' => $endD,
                'current_day' => (int) $now->day,
                'days_remaining_in_cycle' => $daysLeftInCycle,
            ],
            'binary_week_key' => $periodKey,
            'binary_volume_period' => $this->binaryService->isMonthlyBinaryVolume() ? 'monthly' : 'weekly',
            'binary_volume' => [
                'left_pv' => $leftPv,
                'right_pv' => $rightPv,
                'weaker_leg' => $weak,
                'weaker_pv' => $weakVal,
                'stronger_pv' => $strongVal,
                'matched_pv_potential' => $matchable,
            ],
            'rank_pv_progress' => $rankInfo,
            'progress_bars' => [
                'rank_ascent' => $rankAscentBar,
                'auto_okm' => $autoOkmBar,
                'plata_window' => $plataOnboarding['window_bar'],
            ],
            'plata_onboarding' => $plataOnboarding['banner'],
            'direct_referrals_active' => $directosActivos,
            'commission_totals_by_type' => $this->totalesComisionPorTipo($user),
        ];
    }

    /**
     * @param  array{next_slug: ?string, next_threshold_pv: ?string, percent: float|null, current_pv: string}  $rankInfo
     * @return array{percent: float, label: string, subtitle: string, next_slug: ?string, next_threshold_pv: ?string}
     */
    protected function rankAscentBar(array $rankInfo): array
    {
        $nextSlug = $rankInfo['next_slug'] ?? null;
        $percent = $rankInfo['percent'];

        if ($nextSlug === null) {
            $msgs = $rankInfo['missing_messages'] ?? [];
            if (($rankInfo['at_career_cap'] ?? false) === true) {
                return [
                    'percent' => 100.0,
                    'label' => 'Carrera: Triple Diamante Corona (máximo del plan)',
                    'subtitle' => 'PV grupo (estimado): '.($rankInfo['current_pv'] ?? '0'),
                    'next_slug' => null,
                    'next_threshold_pv' => null,
                ];
            }
            if ($msgs !== []) {
                return [
                    'percent' => 0.0,
                    'label' => 'Acceso a rangos de carrera',
                    'subtitle' => implode(' · ', $msgs),
                    'next_slug' => null,
                    'next_threshold_pv' => null,
                ];
            }

            return [
                'percent' => 100.0,
                'label' => 'Rango máximo alcanzado con tus datos actuales',
                'subtitle' => 'PV grupo (estimado): '.($rankInfo['current_pv'] ?? '0'),
                'next_slug' => null,
                'next_threshold_pv' => null,
            ];
        }

        $p = $percent !== null ? (float) $percent : 0.0;
        $extra = isset($rankInfo['missing_messages']) && is_array($rankInfo['missing_messages'])
            ? implode(' · ', $rankInfo['missing_messages'])
            : '';

        return [
            'percent' => min(100.0, max(0.0, $p)),
            'label' => 'Ascenso a '.$nextSlug,
            'subtitle' => 'PV grupo (estimado) '.$rankInfo['current_pv'].' · meta PV '.$rankInfo['next_threshold_pv']
                .($extra !== '' ? ' · '.$extra : ''),
            'next_slug' => $nextSlug,
            'next_threshold_pv' => $rankInfo['next_threshold_pv'] ?? null,
        ];
    }

    /**
     * @return array{percent: float, earned_usd: string, target_usd: string, earned_bob: string, bob_per_usd: string, label: string}
     */
    protected function autoOkmProgress(User $user): array
    {
        $targetUsd = (string) config('mlm.auto_okm.target_total_earnings_usd', '90000');
        $bobPerUsd = bcadd((string) config('mlm.auto_okm.bob_per_usd', '6.96'), '0', 4);

        $earnedBob = bcadd((string) CommissionEvent::query()
            ->where('beneficiary_user_id', $user->id)
            ->sum('amount'), '0', 2);

        if (bccomp($bobPerUsd, '0', 4) !== 1) {
            return [
                'percent' => 0.0,
                'earned_usd' => '0.00',
                'target_usd' => $targetUsd,
                'earned_bob' => $earnedBob,
                'bob_per_usd' => $bobPerUsd,
                'label' => 'Bono Auto OKM (meta en USD)',
            ];
        }

        $earnedUsd = bcdiv($earnedBob, $bobPerUsd, 4);
        $pct = '0';
        if (bccomp($targetUsd, '0', 2) === 1) {
            $pct = bcmul(bcdiv($earnedUsd, $targetUsd, 6), '100', 4);
        }
        $percentFloat = min(100.0, max(0.0, (float) $pct));

        return [
            'percent' => $percentFloat,
            'earned_usd' => bcadd($earnedUsd, '0', 2),
            'target_usd' => bcadd($targetUsd, '0', 2),
            'earned_bob' => $earnedBob,
            'bob_per_usd' => $bobPerUsd,
            'label' => 'Bono Auto OKM — meta USD '.bcadd($targetUsd, '0', 0).' (ganancias totales por comisiones)',
        ];
    }

    /**
     * @return array{banner: array<string, mixed>, window_bar: array<string, mixed>}
     */
    protected function plataOnboardingPayload(User $user): array
    {
        $pvTarget = bcadd((string) config('mlm.onboarding.plata_pv_required', '1200'), '0', 2);
        $months = (int) config('mlm.onboarding.plata_months_window', 3);

        $since90 = Carbon::now()->subDays(90);
        $pv90 = Order::sumCommissionablePvForUserBetween((int) $user->id, $since90, Carbon::now());

        $windowPercent = '0';
        if (bccomp($pvTarget, '0', 2) === 1) {
            $windowPercent = bcmul(bcdiv($pv90, $pvTarget, 6), '100', 2);
        }
        $windowPct = min(100.0, max(0.0, (float) $windowPercent));

        $windowBar = [
            'percent' => $windowPct,
            'label' => 'PV comisionables (últimos 90 días) hacia requisito Acenso hacia paqute fundador',
            'subtitle' => $pv90.' / '.$pvTarget.' PV',
            'pv_90d' => $pv90,
            'pv_target' => $pvTarget,
        ];

        // Regla UX: cuando el usuario ya completó la meta de 1200 PV (en ventana 90 días),
        // ocultar completamente el banner y el medidor.
        if (bccomp($pv90, $pvTarget, 2) >= 0) {
            return [
                'banner' => [
                    'show' => false,
                    'title' => null,
                    'message' => null,
                    'pv_target' => $pvTarget,
                    'months' => $months,
                    'pv_90d' => $pv90,
                ],
                'window_bar' => array_merge($windowBar, ['show' => false]),
            ];
        }

        if ($user->canAccessAdminPanel()) {
            return [
                'banner' => [
                    'show' => false,
                    'title' => null,
                    'message' => null,
                    'pv_target' => $pvTarget,
                    'months' => $months,
                    'pv_90d' => $pv90,
                ],
                'window_bar' => array_merge($windowBar, ['show' => false]),
            ];
        }

        $plataRank = Rank::query()->where('slug', 'plata')->first();
        $show = true;
        if ($plataRank && $user->rank_id) {
            $userRank = $user->rank;
            if ($userRank && (int) $userRank->sort_order >= (int) $plataRank->sort_order) {
                $show = false;
            }
        } elseif (! $plataRank) {
            $pvMes = (string) ($user->monthly_qualifying_pv ?? '0');
            $show = bccomp($pvMes, $pvTarget, 2) < 0;
        }

        if (! $show) {
            return [
                'banner' => [
                    'show' => false,
                    'title' => null,
                    'message' => null,
                    'pv_target' => $pvTarget,
                    'months' => $months,
                    'pv_90d' => $pv90,
                ],
                'window_bar' => array_merge($windowBar, ['show' => false]),
            ];
        }

        $message = 'Debes completar '.$pvTarget.' PV en '.$months.' meses con tus compras; de lo contrario, '
            .'realiza una sola compra de '.$pvTarget.' PV en productos para ascender al paquete fundador. '
            .'Este aviso desaparece cuando alcances el paquete fundador.';

        return [
            'banner' => [
                'show' => true,
                'title' => 'Importante: camino a paquete fundador',
                'message' => $message,
                'pv_target' => $pvTarget,
                'months' => $months,
                'pv_90d' => $pv90,
                'current_monthly_pv' => (string) ($user->monthly_qualifying_pv ?? '0'),
            ],
            'window_bar' => $windowBar,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function totalesComisionPorTipo(User $user): array
    {
        $rows = CommissionEvent::query()
            ->where('beneficiary_user_id', $user->id)
            ->selectRaw('type, COALESCE(SUM(amount), 0) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $out = [];
        foreach ($rows as $type => $total) {
            $out[(string) $type] = bcadd((string) $total, '0', 2);
        }

        return $out;
    }

    /**
     * @param  array{next_threshold_pv: ?string}  $rankInfo
     */
    protected function pvFaltantesSiguienteRango(string $currentPv, array $rankInfo): ?string
    {
        $next = $rankInfo['next_threshold_pv'] ?? null;
        if ($next === null) {
            return null;
        }
        $diff = bcsub((string) $next, $currentPv, 2);

        return bccomp($diff, '0', 2) === 1 ? $diff : '0';
    }
}
