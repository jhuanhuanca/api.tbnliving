<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BinaryLegVolumeWeekly;
use App\Models\CommissionEvent;
use App\Models\Order;
use App\Models\Rank;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Admin\AdminDashboardInsightsService;
use App\Services\Cache\AdminDashboardCacheService;
use App\Services\CareerRankService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminDashboardController extends Controller
{
    public function index(
        CareerRankService $careerRankService,
        AdminDashboardInsightsService $insights,
        AdminDashboardCacheService $dashboardCache,
    ) {
        $payload = $dashboardCache->remember(fn () => $this->buildDashboardPayload($careerRankService, $insights));

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDashboardPayload(CareerRankService $careerRankService, AdminDashboardInsightsService $insights): array
    {
        $pendingWithdrawals = Withdrawal::query()->where('estado', Withdrawal::ESTADO_PENDIENTE)->count();

        $ordersRevenue = (string) Order::query()
            ->where('estado', 'completado')
            ->sum('total');

        $commissionsPaid = (string) CommissionEvent::query()->sum('amount');

        $memberScope = $insights->memberScope();

        $usersNewMonth = (clone $memberScope)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $activeMembers = Schema::hasColumn('users', 'is_mlm_qualified')
            ? (clone $memberScope)->where('is_mlm_qualified', true)->count()
            : 0;

        $periodKey = now()->format('Y-m');
        $binaryVolumeMonth = (string) BinaryLegVolumeWeekly::query()
            ->where('week_key', $periodKey)
            ->sum('volume_pv');

        $salesLast6Months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->copy()->subMonths($i)->startOfMonth();
            $end = $m->copy()->endOfMonth();
            $label = $m->format('Y-m');
            $sum = Order::query()
                ->where('estado', 'completado')
                ->whereBetween('created_at', [$m, $end])
                ->sum('total');
            $cnt = Order::query()
                ->where('estado', 'completado')
                ->whereBetween('created_at', [$m, $end])
                ->count();
            $salesLast6Months[] = [
                'month' => $label,
                'total' => (string) $sum,
                'orders' => $cnt,
            ];
        }

        $commissionsByType = CommissionEvent::query()
            ->selectRaw('type, COALESCE(SUM(amount),0) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($v) => (string) $v)
            ->all();

        // Distribución de rangos "reales" por PV de grupo ligero (sin consultas por usuario).
        $thresholds = (array) config('mlm.residual.rank_thresholds_pv', []);
        arsort($thresholds); // mayor → menor
        $rankNamesBySlug = Rank::query()->pluck('name', 'slug')->all();
        $dist = [];

        $rankUsersQuery = User::query()->where('mlm_role', 'member');
        if (Schema::hasColumn('users', 'account_type')) {
            $rankUsersQuery->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            });
        }

        $rankUsersQuery
            ->with(['referrals:id,sponsor_id,monthly_qualifying_pv', 'registrationPackage:id,pv_points'])
            ->select(['id', 'monthly_qualifying_pv', 'registration_package_id'])
            ->chunk(400, function ($users) use (&$dist, $thresholds, $rankNamesBySlug) {
                foreach ($users as $u) {
                    $gv = (string) (bcadd((string) ($u->monthly_qualifying_pv ?? '0'), '0', 2));
                    foreach ($u->referrals as $r) {
                        $gv = bcadd($gv, (string) ($r->monthly_qualifying_pv ?? '0'), 2);
                    }
                    $slug = 'activo';
                    foreach ($thresholds as $s => $min) {
                        if (bccomp($gv, (string) $min, 2) >= 0) {
                            $slug = (string) $s;
                            break;
                        }
                    }
                    $name = $rankNamesBySlug[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
                    $dist[$slug] = $dist[$slug] ?? ['slug' => $slug, 'name' => $name, 'total' => 0];
                    $dist[$slug]['total']++;
                }
            });

        $rankDistribution = array_values($dist);
        usort($rankDistribution, fn ($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));

        $topMembers = User::query()
            ->with(['rank:id,name,slug', 'registrationPackage:id,pv_points', 'referrals.rank:id,sort_order,slug', 'referrals:id,sponsor_id'])
            ->orderByDesc('monthly_qualifying_pv')
            ->limit(10)
            ->get(['id', 'name', 'member_code', 'monthly_qualifying_pv', 'rank_id'])
            ->map(function (User $u) use ($careerRankService, $rankNamesBySlug) {
                $u->loadMissing('referrals.rank');
                $slug = $careerRankService->displayRankSlug($u);
                $rankName = $rankNamesBySlug[$slug] ?? ($u->rank?->name ?? '—');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'member_code' => $u->member_code,
                    'monthly_qualifying_pv' => (string) ($u->monthly_qualifying_pv ?? '0'),
                    'rank_name' => $rankName,
                    'rank_slug' => $slug,
                ];
            })
            ->values()
            ->all();

        return [
            'users_total' => User::query()->count(),
            'withdrawals_pending' => $pendingWithdrawals,
            'orders_today' => Order::query()
                ->whereDate('created_at', now()->toDateString())
                ->count(),
            'orders_revenue_total' => $ordersRevenue,
            'commissions_paid_total' => $commissionsPaid,
            'users_new_this_month' => $usersNewMonth,
            'binary_volume_current_period' => $binaryVolumeMonth,
            'binary_period_key' => $periodKey,
            'active_members' => $activeMembers,
            'charts' => $this->buildChartsPayload($insights, $salesLast6Months, $commissionsByType, $rankDistribution),
            'top_members' => $topMembers,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $salesLast6Months
     * @param  array<string, string>  $commissionsByType
     * @param  array<int, array<string, mixed>>  $rankDistribution
     * @return array<string, mixed>
     */
    private function buildChartsPayload(
        AdminDashboardInsightsService $insights,
        array $salesLast6Months,
        array $commissionsByType,
        array $rankDistribution,
    ): array {
        $base = [
            'sales_last_6_months' => $salesLast6Months,
            'commissions_by_type' => $commissionsByType,
            'rank_distribution' => $rankDistribution,
        ];

        try {
            return array_merge($base, $insights->buildExtendedCharts());
        } catch (Throwable $e) {
            Log::error('dashboard.extended_charts_failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $base;
        }
    }
}
