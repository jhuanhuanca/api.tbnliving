<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $rows = Withdrawal::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json($rows);
    }

    public function store(Request $request)
    {
        return response()->json([
            'message' => 'Usa el flujo seguro: POST /wallet/withdraw/request y POST /wallet/withdraw/verify-otp.',
            'deprecated' => true,
        ], 410);
    }
}
