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
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\Admin\AdminWithdrawalController;
use App\Http\Controllers\Api\BinaryPlacementController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/internal.php';

/*
| API versionada (panel admin + app miembros). Rutas legacy /api/* intactas.
*/
Route::prefix('v1')->group(function () {
    require __DIR__.'/api_v1.php';
});

$registerMemberApi = require __DIR__.'/definitions/member_api_routes.php';
$registerMemberApi(false);

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('mlm.admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/commissions', [AdminCommissionController::class, 'index']);
        Route::get('/wallet/summary', [AdminWalletController::class, 'summary']);

        Route::get('/tree/search', [AdminTreeController::class, 'search']);
        Route::get('/tree/user/{userId}', [AdminTreeController::class, 'show'])->whereNumber('userId');
        Route::get('/tree/root', [AdminTreeController::class, 'root']);
        Route::get('/tree/{nodeId}/children', [AdminTreeController::class, 'children'])->whereNumber('nodeId');

        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::post('/orders/{order}/confirm-payment', [AdminOrderController::class, 'confirmPayment']);
        Route::get('/orders/{order}/payment-proof', [AdminOrderController::class, 'paymentProof']);
        Route::get('/withdrawals', [AdminWithdrawalController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve']);
        Route::post('/withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject']);
        Route::post('/binary-placement', [BinaryPlacementController::class, 'store']);
        Route::get('/reconciliation/period-closures', [AdminReconciliationController::class, 'periodClosures']);
        Route::get('/reconciliation/commission-summary', [AdminReconciliationController::class, 'commissionSummary']);
        Route::get('/leadership/{monthKey}', [AdminLeadershipController::class, 'show'])
            ->where('monthKey', '[0-9]{4}-[0-9]{2}');

        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::put('/products/{product}', [AdminProductController::class, 'update']);
        Route::delete('/products/{product}', [AdminProductController::class, 'destroy']);
        Route::get('/packages', [AdminPackageController::class, 'index']);
        Route::post('/packages', [AdminPackageController::class, 'store']);
        Route::put('/packages/{package}', [AdminPackageController::class, 'update']);
        Route::delete('/packages/{package}', [AdminPackageController::class, 'destroy']);
    });
});
