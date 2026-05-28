<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\RequestWithdrawOtpRequest;
use App\Http\Requests\Wallet\VerifyWithdrawOtpRequest;
use App\Http\Resources\WithdrawalResource;
use App\Repositories\WithdrawalRepository;
use App\Services\Wallet\SecureWithdrawalService;
use App\Services\Wallet\WithdrawalOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WalletWithdrawController extends Controller
{
    public function __construct(
        protected SecureWithdrawalService $secureWithdrawal,
        protected WithdrawalOtpService $otpService,
        protected WithdrawalRepository $withdrawalRepository,
    ) {}

    /**
     * POST /api/wallet/withdraw/request
     */
    public function requestOtp(RequestWithdrawOtpRequest $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->secureWithdrawal->requestOtp(
            $user,
            $request->resolvedAmount(),
            $request->input('password'),
            $request->resolvedNotes(),
            $request
        );

        $otp = $result['otp'];

        return response()->json([
            'success' => true,
            'message' => 'Código enviado a tu correo.',
            'masked_email' => $result['masked_email'],
            'expires_at' => $otp->expires_at?->toIso8601String(),
            'expires_in_seconds' => $otp->expires_at ? max(0, now()->diffInSeconds($otp->expires_at, false)) : 0,
            'otp_ttl_minutes' => $this->otpService->ttlMinutes(),
            'max_attempts' => $this->otpService->maxAttempts(),
            'resend_cooldown_seconds' => $this->otpService->resendCooldownSeconds(),
            'fee' => $otp->payload['fee'] ?? '0.00',
            'net_amount' => $otp->payload['net_amount'] ?? $request->resolvedAmount(),
        ]);
    }

    /**
     * POST /api/wallet/withdraw/verify-otp
     */
    public function verifyOtp(VerifyWithdrawOtpRequest $request): JsonResponse
    {
        try {
            $withdrawal = $this->secureWithdrawal->verifyAndCreate(
                $request->user(),
                $request->resolvedOtp(),
                $request
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validación fallida.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de retiro registrada. Un administrador la revisará.',
            'withdrawal' => new WithdrawalResource($withdrawal),
        ], 201);
    }

    /**
     * POST /api/wallet/withdraw/resend-otp
     */
    public function resendOtp(Request $request): JsonResponse
    {
        try {
            $result = $this->secureWithdrawal->resendOtp($request->user(), $request);
        } catch (ValidationException $e) {
            $status = isset($e->errors()['cooldown']) ? 429 : 422;

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], $status);
        }

        $otp = $result['otp'];

        return response()->json([
            'success' => true,
            'message' => 'Nuevo código enviado.',
            'masked_email' => $result['masked_email'],
            'expires_at' => $otp->expires_at?->toIso8601String(),
            'resend_cooldown_seconds' => $this->otpService->resendCooldownSeconds(),
        ]);
    }

    /**
     * GET /api/wallet/withdraw/history
     */
    public function history(Request $request): JsonResponse
    {
        $perPage = (int) min($request->query('per_page', 25), 100);
        $paginator = $this->withdrawalRepository->paginateForUser($request->user(), $perPage);

        return response()->json([
            'data' => WithdrawalResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/wallet/withdraw/config — límites para el formulario.
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'currency' => config('mlm.withdrawals.currency', 'BOB'),
            'min_amount' => config('mlm.withdrawals.min_amount', '1.00'),
            'max_amount' => config('mlm.withdrawals.max_amount', '50000.00'),
            'fee_percent' => (float) config('mlm.withdrawals.fee_percent', 0),
            'fee_fixed' => config('mlm.withdrawals.fee_fixed', '0.00'),
            'otp_ttl_minutes' => $this->otpService->ttlMinutes(),
            'otp_max_attempts' => $this->otpService->maxAttempts(),
            'resend_cooldown_seconds' => $this->otpService->resendCooldownSeconds(),
        ]);
    }
}
