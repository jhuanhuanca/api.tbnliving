<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletPaymentToken;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletController extends Controller
{
    public function balance(Request $request, WalletService $walletService)
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'available' => $walletService->saldoDisponible($user),
        ]);
    }

    public function transactions(Request $request, WalletService $walletService)
    {
        $wallet = $walletService->ensureWallet($request->user());

        $rows = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'type', 'amount', 'reference', 'description', 'created_at']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Genera un token de pago (10 minutos) para que otro socio pueda pagar con mi billetera.
     * Retorna el token en claro UNA sola vez (para compartir).
     */
    public function createPaymentToken(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        // Token tipo banco: corto, legible.
        $plain = strtoupper(Str::random(10));
        $plain = substr($plain, 0, 5) . '-' . substr($plain, 5);
        $hash = hash('sha256', $plain);
        $expiresAt = now()->addMinutes(10);

        // Invalidar tokens viejos no usados (higiene).
        WalletPaymentToken::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '<', now())
            ->delete();

        WalletPaymentToken::query()->create([
            'owner_user_id' => $user->id,
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
            'meta' => [
                'label' => 'Token de pago billetera',
            ],
        ]);

        return response()->json([
            'token' => $plain,
            'expires_at' => $expiresAt->toIso8601String(),
            'ttl_seconds' => 600,
        ]);
    }
}
