<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;

class AdminWalletController extends Controller
{
    public function summary(Request $request)
    {
        $rows = WalletTransaction::query()
            ->with('wallet.user:id,name,member_code')
            ->orderByDesc('created_at')
            ->limit(120)
            ->get(['id', 'wallet_id', 'type', 'amount', 'reference', 'description', 'created_at']);

        $income = (float) WalletTransaction::query()->where('amount', '>', 0)->sum('amount');
        $expense = (float) WalletTransaction::query()->where('amount', '<', 0)->sum('amount');
        $balance = $income + $expense;

        return response()->json([
            'balance' => $balance,
            'income' => $income,
            'expense' => $expense,
            'movements' => $rows->map(function (WalletTransaction $t) {
                $u = $t->wallet?->user;
                return [
                    'id' => $t->id,
                    'kind' => ($t->amount ?? 0) >= 0 ? 'income' : 'expense',
                    'concept' => (string) ($t->description ?? $t->type ?? 'Movimiento'),
                    'amount' => (float) $t->amount,
                    'user' => $u ? $u->name : null,
                    'member_code' => $u ? $u->member_code : null,
                    'createdAt' => $t->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }
}

