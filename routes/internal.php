<?php

use App\Http\Controllers\Internal\Sync\SyncCommissionsController;
use App\Http\Controllers\Internal\Sync\SyncCountriesController;
use App\Http\Controllers\Internal\Sync\SyncNetworkController;
use App\Http\Controllers\Internal\Sync\SyncOrdersController;
use App\Http\Controllers\Internal\Sync\SyncProductsController;
use App\Http\Controllers\Internal\Sync\SyncRanksController;
use App\Http\Controllers\Internal\Sync\SyncUsersController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/sync')
    ->middleware(['internal.api', 'throttle:internal-sync'])
    ->group(function () {
        Route::get('/users', [SyncUsersController::class, 'index']);
        Route::get('/orders', [SyncOrdersController::class, 'index']);
        Route::get('/order-items', [\App\Http\Controllers\Internal\Sync\SyncOrderItemsController::class, 'index']);
        Route::get('/commissions', [SyncCommissionsController::class, 'index']);
        Route::get('/network', [SyncNetworkController::class, 'index']);
        Route::get('/ranks', [SyncRanksController::class, 'index']);
        Route::get('/products', [SyncProductsController::class, 'index']);
        Route::get('/countries', [SyncCountriesController::class, 'index']);
    });

/**
 * Panel administrativo externo (backend_panel): mismo payload que /api/admin/*
 * pero autenticación server-to-server con X-Internal-Token (no Sanctum de socio).
 */
Route::prefix('internal/admin')
    ->middleware(['internal.token', 'internal.admin.panel', 'throttle:internal-sync'])
    ->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Api\Admin\AdminDashboardController::class, 'index']);
        Route::get('/users', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'index']);
        Route::get('/commissions', [\App\Http\Controllers\Api\Admin\AdminCommissionController::class, 'index']);
        Route::get('/wallet/summary', [\App\Http\Controllers\Api\Admin\AdminWalletController::class, 'summary']);
        Route::get('/withdrawals', [\App\Http\Controllers\Api\Admin\AdminWithdrawalController::class, 'index']);
        Route::get('/orders', [\App\Http\Controllers\Api\Admin\AdminOrderController::class, 'index']);
        Route::get('/categories', [\App\Http\Controllers\Api\Admin\AdminCategoryController::class, 'index']);
        Route::get('/products', [\App\Http\Controllers\Api\Admin\AdminProductController::class, 'index']);
        Route::get('/packages', [\App\Http\Controllers\Api\Admin\AdminPackageController::class, 'index']);
        Route::get('/tree/search', [\App\Http\Controllers\Api\Admin\AdminTreeController::class, 'search']);
        Route::get('/tree/user/{userId}', [\App\Http\Controllers\Api\Admin\AdminTreeController::class, 'show'])->whereNumber('userId');
        Route::get('/tree/root', [\App\Http\Controllers\Api\Admin\AdminTreeController::class, 'root']);
        Route::get('/tree/{nodeId}/children', [\App\Http\Controllers\Api\Admin\AdminTreeController::class, 'children'])->whereNumber('nodeId');
        Route::get('/reconciliation/period-closures', [\App\Http\Controllers\Api\Admin\AdminReconciliationController::class, 'periodClosures']);
        Route::get('/reconciliation/commission-summary', [\App\Http\Controllers\Api\Admin\AdminReconciliationController::class, 'commissionSummary']);
        Route::get('/leadership/{monthKey}', [\App\Http\Controllers\Api\Admin\AdminLeadershipController::class, 'show'])
            ->where('monthKey', '[0-9]{4}-[0-9]{2}');

        Route::post('/orders/{order}/confirm-payment', [\App\Http\Controllers\Api\Admin\AdminOrderController::class, 'confirmPayment']);
        Route::post('/orders/{order}/cancel', [\App\Http\Controllers\Api\Admin\AdminOrderController::class, 'cancel']);
        Route::post('/withdrawals/{withdrawal}/approve', [\App\Http\Controllers\Api\Admin\AdminWithdrawalController::class, 'approve']);
        Route::post('/withdrawals/{withdrawal}/reject', [\App\Http\Controllers\Api\Admin\AdminWithdrawalController::class, 'reject']);
    });

