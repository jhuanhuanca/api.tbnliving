<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ResolvesInternalPanelActor;
use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use App\Support\WalletSettingsPresenter;
use Illuminate\Http\Request;

class AdminWithdrawalController extends Controller
{
    use ResolvesInternalPanelActor;
    public function index(Request $request)
    {
        $this->authorize('viewAny', Withdrawal::class);

        $estado = $request->query('estado', Withdrawal::ESTADO_PENDIENTE);

        $q = Withdrawal::query()
            ->with('user:id,name,email,member_code,meta')
            ->orderByDesc('id');

        if ($estado !== 'all') {
            $q->where('estado', $estado);
        }

        $paginator = $q->paginate((int) min($request->query('per_page', 25), 100));

        $paginator->getCollection()->transform(function (Withdrawal $withdrawal) {
            if ($withdrawal->user) {
                WalletSettingsPresenter::attachToUser($withdrawal->user);
            }

            return $withdrawal;
        });

        return response()->json($paginator);
    }

    private function freshWithdrawalResponse(Withdrawal $withdrawal)
    {
        $withdrawal->loadMissing(['user:id,name,email,member_code,meta', 'processor']);
        if ($withdrawal->user) {
            WalletSettingsPresenter::attachToUser($withdrawal->user);
        }

        return response()->json($withdrawal);
    }

    public function approve(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawalService)
    {
        $this->authorize('approve', $withdrawal);
        $withdrawalService->marcarAprobado($withdrawal, $this->resolveActor($request));

        return $this->freshWithdrawalResponse($withdrawal->fresh());
    }

    public function reject(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawalService)
    {
        $this->authorize('reject', $withdrawal);
        $data = $request->validate(['notas_admin' => 'nullable|string|max:2000']);
        $withdrawalService->marcarRechazado($withdrawal, $this->resolveActor($request), $data['notas_admin'] ?? null);

        return $this->freshWithdrawalResponse($withdrawal->fresh());
    }
}
