<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ResolvesInternalPanelActor;
use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;

class AdminWithdrawalController extends Controller
{
    use ResolvesInternalPanelActor;
    public function index(Request $request)
    {
        $this->authorize('viewAny', Withdrawal::class);

        $estado = $request->query('estado', Withdrawal::ESTADO_PENDIENTE);

        $q = Withdrawal::query()->with('user:id,name,email,member_code')->orderByDesc('id');

        if ($estado !== 'all') {
            $q->where('estado', $estado);
        }

        return response()->json($q->paginate((int) min($request->query('per_page', 25), 100)));
    }

    public function approve(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawalService)
    {
        $this->authorize('approve', $withdrawal);
        $withdrawalService->marcarAprobado($withdrawal, $this->resolveActor($request));

        return response()->json($withdrawal->fresh(['user', 'processor']));
    }

    public function reject(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawalService)
    {
        $this->authorize('reject', $withdrawal);
        $data = $request->validate(['notas_admin' => 'nullable|string|max:2000']);
        $withdrawalService->marcarRechazado($withdrawal, $this->resolveActor($request), $data['notas_admin'] ?? null);

        return response()->json($withdrawal->fresh(['user', 'processor']));
    }
}
