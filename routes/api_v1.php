<?php

use App\Http\Controllers\Api\Admin\AdminCategoryController;
use App\Http\Controllers\Api\Admin\AdminCommissionController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminLeadershipController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminPackageController;
use App\Http\Controllers\Api\Admin\AdminProductController;
use App\Http\Controllers\Api\Admin\AdminReconciliationController;
use App\Http\Controllers\Api\Admin\AdminTreeController;
use App\Http\Controllers\Api\Admin\AdminSupportTicketController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\Admin\AdminWithdrawalController;
use App\Http\Controllers\Api\Admin\AdminEventController;
use App\Http\Controllers\Api\Admin\AdminEventRegistrationController;
use App\Http\Controllers\Api\Admin\AdminNewsController;
use App\Http\Controllers\Api\Admin\AdminPrintController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\BinaryPlacementController;
use App\Http\Controllers\Api\V1\Admin\AdminV1AuthController;
use App\Http\Controllers\Api\V1\Admin\AdminV1DashboardController;
use App\Http\Controllers\Api\V1\Analytics\AdminV1AnalyticsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Panel admin (admin.tbnliving.com) + app miembros (front.tbnliving.com)
| Prefijo final: /api/v1/...
| Las rutas legacy /api/* se mantienen sin cambios.
|--------------------------------------------------------------------------
*/

$registerMemberApi = require __DIR__.'/definitions/member_api_routes.php';
$registerMemberApi(true);

Route::prefix('public')->group(function () {
    Route::get('events/{event}/flyer', [EventController::class, 'flyer'])->whereNumber('event');
    Route::get('news/{news}/image', [NewsController::class, 'image'])->whereNumber('news');
});

Route::prefix('admin/auth')->group(function () {
    Route::post('login', [AdminV1AuthController::class, 'login'])
        ->middleware('throttle:15,1');

    Route::middleware(['auth:sanctum', 'mlm.admin'])->group(function () {
        Route::get('me', [AdminV1AuthController::class, 'me']);
        Route::post('logout', [AdminV1AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'mlm.admin'])->group(function () {
    Route::prefix('admin')->group(function () {
        Route::get('dashboard/kpis', [AdminV1DashboardController::class, 'kpis']);
        Route::get('dashboard', [AdminDashboardController::class, 'index']);

        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('commissions', [AdminCommissionController::class, 'index']);
        Route::get('wallet/summary', [AdminWalletController::class, 'summary']);

        Route::get('tree/search', [AdminTreeController::class, 'search']);
        Route::get('tree/user/{userId}', [AdminTreeController::class, 'show'])->whereNumber('userId');
        Route::get('tree/root', [AdminTreeController::class, 'root']);
        Route::get('tree/{nodeId}/children', [AdminTreeController::class, 'children'])->whereNumber('nodeId');

        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::post('orders/{order}/confirm-payment', [AdminOrderController::class, 'confirmPayment']);
        Route::get('orders/{order}/payment-proof', [AdminOrderController::class, 'paymentProof']);
        Route::get('orders/{order}/invoice/print', [AdminPrintController::class, 'orderInvoice']);
        Route::get('withdrawals', [AdminWithdrawalController::class, 'index']);
        Route::post('withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve']);
        Route::post('withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject']);
        Route::get('withdrawals/{withdrawal}/print', [AdminPrintController::class, 'withdrawal']);
        Route::get('support-tickets', [AdminSupportTicketController::class, 'index']);
        Route::patch('support-tickets/{supportTicket}', [AdminSupportTicketController::class, 'update']);
        Route::post('binary-placement', [BinaryPlacementController::class, 'store']);
        Route::get('reconciliation/period-closures', [AdminReconciliationController::class, 'periodClosures']);
        Route::get('reconciliation/commission-summary', [AdminReconciliationController::class, 'commissionSummary']);
        Route::get('leadership/{monthKey}', [AdminLeadershipController::class, 'show'])
            ->where('monthKey', '[0-9]{4}-[0-9]{2}');

        Route::get('categories', [AdminCategoryController::class, 'index']);
        Route::get('products', [AdminProductController::class, 'index']);
        Route::post('products', [AdminProductController::class, 'store']);
        Route::put('products/{product}', [AdminProductController::class, 'update']);
        Route::delete('products/{product}', [AdminProductController::class, 'destroy']);
        Route::get('packages', [AdminPackageController::class, 'index']);
        Route::post('packages', [AdminPackageController::class, 'store']);
        Route::put('packages/{package}', [AdminPackageController::class, 'update']);
        Route::delete('packages/{package}', [AdminPackageController::class, 'destroy']);

        Route::get('events', [AdminEventController::class, 'index']);
        Route::post('events', [AdminEventController::class, 'store']);
        Route::put('events/{event}', [AdminEventController::class, 'update'])->whereNumber('event');
        Route::delete('events/{event}', [AdminEventController::class, 'destroy'])->whereNumber('event');
        Route::get('events/{event}/flyer', [AdminEventController::class, 'flyer'])->whereNumber('event');

        Route::get('news', [AdminNewsController::class, 'index']);
        Route::post('news', [AdminNewsController::class, 'store']);
        Route::put('news/{news}', [AdminNewsController::class, 'update'])->whereNumber('news');
        Route::delete('news/{news}', [AdminNewsController::class, 'destroy'])->whereNumber('news');
        Route::get('news/{news}/image', [AdminNewsController::class, 'image'])->whereNumber('news');

        Route::get('event-registrations', [AdminEventRegistrationController::class, 'index']);
        Route::post('event-registrations/{registration}/confirm-payment', [AdminEventRegistrationController::class, 'confirmPayment'])
            ->whereNumber('registration');
        Route::get('event-registrations/{registration}/payment-proof', [AdminEventRegistrationController::class, 'paymentProof'])
            ->whereNumber('registration');
    });

    Route::prefix('analytics')->group(function () {
        Route::get('overview', [AdminV1AnalyticsController::class, 'overview']);
        Route::get('sales/daily', [AdminV1AnalyticsController::class, 'salesDaily']);
        Route::get('sales/monthly', [AdminV1AnalyticsController::class, 'salesMonthly']);
        Route::get('products/top', [AdminV1AnalyticsController::class, 'productsTop']);
        Route::get('products/daily', [AdminV1AnalyticsController::class, 'productsDaily']);
        Route::get('growth/mlm', [AdminV1AnalyticsController::class, 'growthMlm']);
        Route::get('countries/metrics', [AdminV1AnalyticsController::class, 'countriesMetrics']);
        Route::get('network/growth', [AdminV1AnalyticsController::class, 'networkGrowth']);
        Route::get('commissions/summary', [AdminV1AnalyticsController::class, 'commissionsSummary']);
    });
});
