<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthRegisterController;
use App\Http\Controllers\Api\AuthRegisterPreferenteController;
use App\Http\Controllers\Api\BinaryPlacementController;
use App\Http\Controllers\Api\BinaryPlacementSelfController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MlmBonusCalculatorController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PackageCatalogController;
use App\Http\Controllers\Api\ProductCatalogController;
use App\Http\Controllers\Api\PublicCountryController;
use App\Http\Controllers\Api\PublicLandingController;
use App\Http\Controllers\Api\SponsorLookupController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\V1\Member\V1MemberAuthController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventRegistrationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\WalletWithdrawController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Rutas API de miembros (públicas + auth:sanctum).
 *
 * @param  bool  $v1Auth  Si true, login/logout/me bajo prefijo `auth/` (para /api/v1).
 */
return function (bool $v1Auth = false): void {
    if ($v1Auth) {
        Route::prefix('auth')->group(function () {
            Route::post('login', [V1MemberAuthController::class, 'login'])
                ->middleware('throttle:15,1');
            Route::middleware('auth:sanctum')->group(function () {
                Route::get('me', [V1MemberAuthController::class, 'me']);
                Route::post('logout', [V1MemberAuthController::class, 'logout']);
            });
        });
    } else {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:15,1');
    }

    Route::post('/register', [AuthRegisterController::class, 'register']);
    Route::post('/register/preferred-customer', [AuthRegisterPreferenteController::class, 'register']);

    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendCode'])
        ->middleware('throttle:password-reset-send');
    Route::post('/verify-code', [ForgotPasswordController::class, 'verifyCode'])
        ->middleware('throttle:password-reset-verify');
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])
        ->middleware('throttle:password-reset-reset');

    Route::post('/email/resend-verification', function (Request $request) {
        $request->validate(['email' => ['required', 'email']]);
        $user = \App\Models\User::query()->where('email', $request->email)->first();
        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'Si el correo existe y aún no está verificado, te enviamos un enlace.',
        ]);
    });

    Route::get('/public/sponsors/{code}', [SponsorLookupController::class, 'show'])
        ->where('code', '[A-Za-z0-9._-]+');

    Route::get('/packages', [PackageCatalogController::class, 'index']);
    Route::get('/countries', [PublicCountryController::class, 'index']);

    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show'])->whereNumber('event');
    Route::get('/news', [NewsController::class, 'index']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/me/binary-placement', [BinaryPlacementSelfController::class, 'store']);

        Route::get('/me', [MeController::class, 'profile']);
        Route::get('/me/dashboard', [MeController::class, 'dashboard']);
        Route::get('/me/referrals', [MeController::class, 'referrals']);
        Route::get('/me/unilevel-tree', [MeController::class, 'unilevelTree']);
        Route::get('/me/commissions', [MeController::class, 'commissions']);
        Route::get('/me/notifications', [MeController::class, 'notifications']);
        Route::delete('/me/notifications', [MeController::class, 'dismissNotifications']);
        Route::get('/me/binary-tree', [MeController::class, 'binaryTree']);
        Route::get('/me/binary-tree/children', [MeController::class, 'binaryTreeChildren']);
        Route::get('/me/binary-tree/search', [MeController::class, 'binaryTreeSearch']);
        Route::get('/me/founder', [MeController::class, 'founder']);
        Route::post('/me/founder/purchase', [MeController::class, 'founderPurchase']);
        Route::put('/me/profile', [AccountController::class, 'updateProfile']);
        Route::put('/me/password', [AccountController::class, 'changePassword']);
        Route::get('/me/landing', [AccountController::class, 'getLanding']);
        Route::put('/me/landing', [AccountController::class, 'updateLanding']);
        Route::get('/me/wallet-settings', [AccountController::class, 'getWalletSettings']);
        Route::put('/me/wallet-settings', [AccountController::class, 'updateWalletSettings']);
        Route::get('/support/tickets', [SupportTicketController::class, 'index']);
        Route::post('/support/tickets', [SupportTicketController::class, 'store']);

        Route::get('/wallet/balance', [WalletController::class, 'balance']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('/wallet/payment-token', [WalletController::class, 'createPaymentToken']);

        Route::get('/wallet/withdraw/config', [WalletWithdrawController::class, 'config']);
        Route::post('/wallet/withdraw/request', [WalletWithdrawController::class, 'requestOtp'])
            ->middleware('throttle:withdraw-otp-request');
        Route::post('/wallet/withdraw/verify-otp', [WalletWithdrawController::class, 'verifyOtp'])
            ->middleware('throttle:withdraw-otp-verify');
        Route::post('/wallet/withdraw/resend-otp', [WalletWithdrawController::class, 'resendOtp'])
            ->middleware('throttle:withdraw-otp-resend');
        Route::get('/wallet/withdraw/history', [WalletWithdrawController::class, 'history']);
        Route::get('/me/2fa/status', [TwoFactorController::class, 'status']);
        Route::post('/me/2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('/me/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/me/2fa/disable', [TwoFactorController::class, 'disable']);

        Route::get('/products', [ProductCatalogController::class, 'index']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/event-registrations', [EventRegistrationController::class, 'index']);
        Route::post('/event-registrations', [EventRegistrationController::class, 'store']);
        Route::get('/me/invoices', [InvoiceController::class, 'index']);
        Route::get('/me/invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::post('/withdrawals', [WithdrawalController::class, 'store']);
        Route::get('/withdrawals', [WithdrawalController::class, 'index']);

        Route::post('/mlm/bonus/calculate', [MlmBonusCalculatorController::class, 'calculate']);
    });

    Route::get('/public/landing/{memberCode}', [PublicLandingController::class, 'show']);

    if (! $v1Auth) {
        Route::post('/logout', function (Request $request) {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Sesión cerrada correctamente',
            ]);
        })->middleware('auth:sanctum');
    }
};
