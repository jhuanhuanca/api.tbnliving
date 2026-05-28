<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Controller;
use App\Services\Analytics\AdminAnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminV1AnalyticsController extends Controller
{
    public function __construct(
        private readonly AdminAnalyticsService $analytics,
    ) {}

    public function overview(Request $request, AdminDashboardController $dashboard): JsonResponse
    {
        return $this->respond('overview', function () use ($dashboard) {
            $kpis = app()->call([$dashboard, 'index'])->getData(true);

            return [
                'users_total' => $kpis['users_total'] ?? 0,
                'orders_revenue_total' => $kpis['orders_revenue_total'] ?? '0',
                'commissions_paid_total' => $kpis['commissions_paid_total'] ?? '0',
                'withdrawals_pending' => $kpis['withdrawals_pending'] ?? 0,
                'users_new_this_month' => $kpis['users_new_this_month'] ?? 0,
                'binary_volume_current_period' => $kpis['binary_volume_current_period'] ?? '0',
            ];
        });
    }

    public function salesDaily(Request $request): JsonResponse
    {
        return $this->respond('sales.daily', function () use ($request) {
            return $this->analytics->salesDaily(
                $request->date('from'),
                $request->date('to'),
            )->values()->all();
        });
    }

    public function salesMonthly(Request $request, AdminDashboardController $dashboard): JsonResponse
    {
        return $this->respond('sales.monthly', function () use ($dashboard) {
            $charts = app()->call([$dashboard, 'index'])->getData(true);

            return $charts['charts']['sales_last_6_months'] ?? [];
        });
    }

    public function productsTop(Request $request): JsonResponse
    {
        return $this->respond('products.top', function () use ($request) {
            $limit = min(50, max(1, (int) $request->input('limit', 10)));

            return $this->analytics->topProducts(
                $limit,
                $request->date('from'),
                $request->date('to'),
            )->values()->all();
        });
    }

    public function productsDaily(Request $request): JsonResponse
    {
        return ApiResponse::success([]);
    }

    public function growthMlm(Request $request): JsonResponse
    {
        return $this->respond('growth.mlm', fn () => $this->analytics->growthMlm());
    }

    public function countriesMetrics(Request $request): JsonResponse
    {
        return $this->respond('countries.metrics', function () {
            return $this->analytics->countriesMetrics()->values()->all();
        });
    }

    public function networkGrowth(Request $request): JsonResponse
    {
        return $this->respond('network.growth', function () {
            return $this->analytics->networkGrowth()->values()->all();
        });
    }

    public function commissionsSummary(Request $request): JsonResponse
    {
        return $this->respond('commissions.summary', fn () => $this->analytics->commissionsSummary());
    }

    private function respond(string $context, callable $callback): JsonResponse
    {
        try {
            $data = $this->analytics->run($context, $callback);

            return ApiResponse::success($data);
        } catch (Throwable) {
            return ApiResponse::error(
                'No se pudieron calcular las métricas de analytics.',
                500,
                ['context' => $context],
                'analytics_error',
            );
        }
    }
}
