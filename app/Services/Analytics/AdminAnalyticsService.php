<?php

namespace App\Services\Analytics;

use App\Models\CommissionEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminAnalyticsService
{
    /**
     * Top productos por ingresos (líneas con product_id en pedidos completados).
     *
     * @return Collection<int, array{product_id: int, product_name: string|null, quantity: int, revenue: string}>
     */
    public function topProducts(int $limit = 10, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $limit = min(50, max(1, $limit));

        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.estado', 'completado')
            ->whereNotNull('order_items.product_id');

        if ($from) {
            $query->where(function ($w) use ($from) {
                $w->where('orders.completed_at', '>=', $from->copy()->startOfDay())
                    ->orWhere(function ($w2) use ($from) {
                        $w2->whereNull('orders.completed_at')
                            ->where('orders.created_at', '>=', $from->copy()->startOfDay());
                    });
            });
        }

        if ($to) {
            $query->where(function ($w) use ($to) {
                $w->where('orders.completed_at', '<=', $to->copy()->endOfDay())
                    ->orWhere(function ($w2) use ($to) {
                        $w2->whereNull('orders.completed_at')
                            ->where('orders.created_at', '<=', $to->copy()->endOfDay());
                    });
            });
        }

        $rows = $query
            ->select([
                'order_items.product_id',
                DB::raw('MAX(products.name) as product_name'),
                DB::raw('COALESCE(SUM(order_items.cantidad), 0) as quantity'),
                DB::raw('COALESCE(SUM(order_items.precio_total), 0) as revenue'),
            ])
            ->groupBy('order_items.product_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'quantity' => (int) $row->quantity,
            'revenue' => number_format((float) $row->revenue, 2, '.', ''),
        ]);
    }

    public function salesDaily(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();

        return Order::query()
            ->where('estado', 'completado')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders, COALESCE(SUM(total),0) as revenue')
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    public function countriesMetrics(int $limit = 20): Collection
    {
        return User::query()
            ->selectRaw('country_code, COUNT(*) as members')
            ->whereNotNull('country_code')
            ->where('country_code', '!=', '')
            ->groupBy('country_code')
            ->orderByDesc('members')
            ->limit($limit)
            ->get();
    }

    public function networkGrowth(int $days = 60): Collection
    {
        return User::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as signups')
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    public function commissionsSummary(): array
    {
        $byType = CommissionEvent::query()
            ->selectRaw('type, COALESCE(SUM(amount),0) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($v) => number_format((float) $v, 2, '.', ''))
            ->all();

        return [
            'by_type' => $byType,
            'total' => number_format((float) CommissionEvent::query()->sum('amount'), 2, '.', ''),
        ];
    }

    public function growthMlm(): array
    {
        $newUsers = User::query()->where('created_at', '>=', now()->startOfMonth())->count();
        $totalUsers = User::query()->count();

        return [
            'period' => now()->format('Y-m'),
            'new_members' => $newUsers,
            'total_members' => $totalUsers,
            'growth_rate' => $totalUsers > 0 ? round(($newUsers / $totalUsers) * 100, 2) : 0,
        ];
    }

    /**
     * @param  callable(): mixed  $callback
     */
    public function run(string $context, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            Log::error('analytics.query_failed', [
                'context' => $context,
                'sql_state' => $e->errorInfo[0] ?? null,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('analytics.failed', [
                'context' => $context,
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
