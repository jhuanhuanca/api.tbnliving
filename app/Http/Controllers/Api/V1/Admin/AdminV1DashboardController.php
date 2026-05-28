<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;

class AdminV1DashboardController extends Controller
{
    public function kpis(AdminDashboardController $dashboard)
    {
        // Resolver todas las dependencias de index() vía contenedor (evita ArgumentCountError).
        $response = app()->call([$dashboard, 'index']);
        $data = $response->getData(true);
        $asOf = now()->toIso8601String();

        $items = [
            ['key' => 'users_total', 'value' => (string) ($data['users_total'] ?? 0), 'as_of_at' => $asOf],
            ['key' => 'orders_today', 'value' => (string) ($data['orders_today'] ?? 0), 'as_of_at' => $asOf],
            ['key' => 'orders_revenue_total', 'value' => (string) ($data['orders_revenue_total'] ?? '0'), 'as_of_at' => $asOf],
            ['key' => 'commissions_paid_total', 'value' => (string) ($data['commissions_paid_total'] ?? '0'), 'as_of_at' => $asOf],
            ['key' => 'withdrawals_pending', 'value' => (string) ($data['withdrawals_pending'] ?? 0), 'as_of_at' => $asOf],
            ['key' => 'users_new_this_month', 'value' => (string) ($data['users_new_this_month'] ?? 0), 'as_of_at' => $asOf],
            ['key' => 'binary_volume_current_period', 'value' => (string) ($data['binary_volume_current_period'] ?? '0'), 'as_of_at' => $asOf],
            ['key' => 'sales_daily_total', 'value' => (string) ($data['orders_revenue_total'] ?? '0'), 'as_of_at' => $asOf],
            ['key' => 'active_users', 'value' => (string) ($data['active_members'] ?? 0), 'as_of_at' => $asOf],
            ['key' => 'active_members', 'value' => (string) ($data['active_members'] ?? 0), 'as_of_at' => $asOf],
        ];

        return ApiResponse::success([
            ...$data,
            'items' => $items,
        ], 'KPIs del panel administrativo');
    }
}
