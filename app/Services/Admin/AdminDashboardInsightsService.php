<?php

namespace App\Services\Admin;

use App\Models\CommissionEvent;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\UserMonthlyRankSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminDashboardInsightsService
{
    /** @var array<string, string> */
    private const COMMISSION_TYPE_LABELS = [
        'bir' => 'Bono inicio rápido (BIR)',
        'binary' => 'Binario',
        'residual' => 'Residual / equipo',
        'leadership' => 'Liderazgo',
        'direct' => 'Venta directa',
        'retail' => 'Cliente preferente',
    ];

    public function memberScope(): Builder
    {
        $query = User::query()->where('mlm_role', 'member');

        if (Schema::hasColumn('users', 'account_type')) {
            $query->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExtendedCharts(): array
    {
        $charts = [
            'commissions_by_type_labeled' => [],
            'revenue_by_source' => [],
            'partner_registration_revenue_by_month' => [],
            'monthly_member_activation' => [],
            'orders_revenue_by_tipo' => [],
        ];

        foreach ([
            'commissions_by_type_labeled' => fn () => $this->commissionsByTypeLabeled(),
            'revenue_by_source' => fn () => $this->revenueBySource(),
            'partner_registration_revenue_by_month' => fn () => $this->partnerRegistrationRevenueByMonth(6),
            'monthly_member_activation' => fn () => $this->monthlyMemberActivation(6),
            'orders_revenue_by_tipo' => fn () => $this->ordersRevenueByTipo(),
        ] as $key => $builder) {
            try {
                $charts[$key] = $builder();
            } catch (\Throwable $e) {
                Log::warning('dashboard.chart_failed', [
                    'chart' => $key,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $charts;
    }

    /**
     * @return list<array{type: string, label: string, total: string}>
     */
    private function commissionsByTypeLabeled(): array
    {
        if (! Schema::hasTable('commission_events')) {
            return [];
        }

        $rows = CommissionEvent::query()
            ->selectRaw('type, COALESCE(SUM(amount),0) as total')
            ->groupBy('type')
            ->orderByRaw('COALESCE(SUM(amount),0) DESC')
            ->get();

        return $rows->map(function ($row) {
            $type = (string) $row->type;

            return [
                'type' => $type,
                'label' => self::COMMISSION_TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type)),
                'total' => (string) $row->total,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{source: string, label: string, total: string}>
     */
    private function revenueBySource(): array
    {
        $productTotal = '0';
        $packageTotal = '0';

        if (Schema::hasTable('orders')) {
            $orderByTipo = Order::query()
                ->where('estado', 'completado')
                ->selectRaw('tipo, COALESCE(SUM(total),0) as total')
                ->groupBy('tipo')
                ->pluck('total', 'tipo');

            foreach ($orderByTipo as $tipo => $sum) {
                $tipo = (string) $tipo;
                $sum = (string) $sum;
                if (in_array($tipo, ['producto', 'mixto'], true)) {
                    $productTotal = bcadd($productTotal, $sum, 2);
                } elseif (in_array($tipo, ['paquete', 'fundador'], true)) {
                    $packageTotal = bcadd($packageTotal, $sum, 2);
                } else {
                    $productTotal = bcadd($productTotal, $sum, 2);
                }
            }
        }

        $registrationFromUsers = '0';
        if (
            Schema::hasTable('packages')
            && Schema::hasTable('users')
            && Schema::hasColumn('users', 'registration_package_id')
        ) {
            $registrationFromUsers = (string) Package::query()
                ->join('users', 'users.registration_package_id', '=', 'packages.id')
                ->whereNotNull('users.registration_package_id')
                ->sum('packages.price');
        }

        return [
            [
                'source' => 'orders_products',
                'label' => 'Pedidos — productos',
                'total' => $productTotal,
            ],
            [
                'source' => 'orders_packages',
                'label' => 'Pedidos — paquetes',
                'total' => $packageTotal,
            ],
            [
                'source' => 'registrations_catalog',
                'label' => 'Inscripciones (catálogo)',
                'total' => $registrationFromUsers,
            ],
        ];
    }

    /**
     * @return list<array{tipo: string, label: string, total: string}>
     */
    private function ordersRevenueByTipo(): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $labels = [
            'producto' => 'Productos',
            'paquete' => 'Paquetes',
            'fundador' => 'Fundador',
            'mixto' => 'Mixto',
            'compra' => 'Compra',
        ];

        return Order::query()
            ->where('estado', 'completado')
            ->selectRaw('tipo, COALESCE(SUM(total),0) as total')
            ->groupBy('tipo')
            ->orderByRaw('COALESCE(SUM(total),0) DESC')
            ->get()
            ->map(fn ($row) => [
                'tipo' => (string) $row->tipo,
                'label' => $labels[(string) $row->tipo] ?? ucfirst((string) $row->tipo),
                'total' => (string) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{month: string, total: string, signups: int}>
     */
    private function partnerRegistrationRevenueByMonth(int $months): array
    {
        $canSumPackages = Schema::hasTable('packages')
            && Schema::hasTable('users')
            && Schema::hasColumn('users', 'registration_package_id');

        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->copy()->subMonths($i)->startOfMonth();
            $end = $m->copy()->endOfMonth();
            $label = $m->format('Y-m');

            $sum = '0';
            if ($canSumPackages) {
                $sum = (string) Package::query()
                    ->join('users', 'users.registration_package_id', '=', 'packages.id')
                    ->whereBetween('users.created_at', [$m, $end])
                    ->sum('packages.price');
            }

            $signups = $this->memberScope()
                ->whereBetween('created_at', [$m, $end])
                ->count();

            $out[] = [
                'month' => $label,
                'total' => $sum,
                'signups' => $signups,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{month: string, active: int, inactive: int, signups: int, threshold_pv: float}>
     */
    private function monthlyMemberActivation(int $months): array
    {
        $threshold = (float) config('mlm.career.direct_active_min_pv', 50);
        $currentMonth = now()->format('Y-m');
        $hasSnapshots = Schema::hasTable('user_monthly_rank_snapshots');
        $hasQualifiedFlag = Schema::hasColumn('users', 'is_mlm_qualified');
        $out = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->copy()->subMonths($i)->startOfMonth();
            $end = $m->copy()->endOfMonth();
            $label = $m->format('Y-m');

            $signups = $this->memberScope()
                ->whereBetween('created_at', [$m, $end])
                ->count();

            $membersAtEnd = $this->memberScope()
                ->where('created_at', '<=', $end)
                ->count();

            $active = 0;
            if ($label === $currentMonth && $hasQualifiedFlag) {
                $active = $this->memberScope()->where('is_mlm_qualified', true)->count();
            } elseif ($hasSnapshots) {
                $active = $this->countActiveMembersFromSnapshots($label, $threshold);
            }

            $out[] = [
                'month' => $label,
                'active' => $active,
                'inactive' => max(0, $membersAtEnd - $active),
                'signups' => $signups,
                'threshold_pv' => $threshold,
            ];
        }

        return $out;
    }

    private function countActiveMembersFromSnapshots(string $monthKey, float $threshold): int
    {
        $query = UserMonthlyRankSnapshot::query()
            ->where('month_key', $monthKey)
            ->where('qualifying_pv', '>=', $threshold);

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'mlm_role')) {
            $query->whereIn('user_id', $this->memberScope()->select('id'));
        }

        return (int) $query->distinct()->count('user_id');
    }

    public static function commissionTypeLabel(string $type): string
    {
        return self::COMMISSION_TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
}
