<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\FounderPackages;
use App\Services\BinaryService;
use App\Services\CommissionEngine;
use App\Services\CommissionService;
use App\Services\UserQualificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Encola el cálculo BIR, residual por pedido y acumulación binaria (idempotente).
 */
class ProcessOrderMlmAccrualsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $orderId)
    {
        $this->onQueue(config('mlm.queues.binary', 'default'));
    }

    public function handle(
        CommissionEngine $commissionEngine,
        CommissionService $commissionService,
        BinaryService $binaryService,
        UserQualificationService $qualificationService
    ): void {
        $order = Order::query()
            ->with(['items.package', 'items.product', 'user.rank'])
            ->find($this->orderId);

        if (! $order || $order->estado !== 'completado') {
            return;
        }

        $this->applyFounderProgressFromCompletedOrder($order);

        User::query()->whereKey($order->user_id)->update(['last_mlm_activity_at' => now()]);

        $buyer = $order->user;
        if ($buyer && $buyer->isPreferredCustomer()) {
            $order->loadMissing(['items.product', 'user']);
            $commissionService->acreditarBonosVentaDirectaPreferente($order->fresh(['items.product', 'user']));

            return;
        }
        foreach ($order->items as $item) {
            if ($item->package_id && $buyer->activation_paid_at === null && ! $buyer->canAccessAdminPanel()) {
                $buyer->forceFill(['activation_paid_at' => now()])->save();
                break;
            }
        }

        $buyer = $buyer->fresh();
        if (! $buyer->canAccessAdminPanel()) {
            $pref = (string) ($buyer->preferred_binary_leg ?? '');
            if (in_array($pref, [\App\Models\BinaryPlacement::LEG_LEFT, \App\Models\BinaryPlacement::LEG_RIGHT], true)) {
                $binaryService->placeUserDirectUnderSponsor($buyer, $pref);
            }
            if (! $buyer->binaryPlacement()->exists()) {
                $binaryService->placeUserInFirstFreeSlot($buyer);
            }
            $buyer = $buyer->fresh();
        }

        $commissionEngine->process($order);
        if ($buyer->binaryPlacement()->exists()) {
            $binaryService->acumularVolumenBinarioPorPedido($order);
        }
        $qualificationService->actualizarCalificacionMensual($order->user->fresh());
    }

    /**
     * Suma PV del paquete fundador al completar el pedido (idempotente por línea).
     */
    protected function applyFounderProgressFromCompletedOrder(Order $order): void
    {
        $buyer = User::query()->whereKey($order->user_id)->first();
        if (! $buyer || $buyer->isPreferredCustomer()) {
            return;
        }

        $order->loadMissing('items');
        $toApply = [];
        foreach ($order->items as $item) {
            $m = $item->meta ?? [];
            if (empty($m['founder_package']) || ! empty($m['founder_progress_applied'])) {
                continue;
            }
            $slug = (string) $m['founder_package'];
            if (! FounderPackages::isValidSlug($slug)) {
                continue;
            }
            $toApply[] = ['item' => $item, 'pv' => FounderPackages::pv($slug), 'meta' => $m];
        }

        if ($toApply === []) {
            return;
        }

        DB::transaction(function () use ($buyer, $toApply) {
            $u = User::query()->whereKey($buyer->id)->lockForUpdate()->first();
            if (! $u) {
                return;
            }
            $current = bcadd((string) ($u->pv_actual ?? '0'), '0', 2);

            foreach ($toApply as $row) {
                $current = bcadd($current, $row['pv'], 2);
                $m = $row['meta'];
                OrderItem::query()->whereKey($row['item']->id)->update([
                    'meta' => array_merge($m, ['founder_progress_applied' => true]),
                ]);
            }

            $completed = bccomp($current, '1200', 2) >= 0;
            $u->forceFill([
                'pv_actual' => $current,
                'paquete_fundador_completado' => $completed ? true : (bool) ($u->paquete_fundador_completado ?? false),
            ])->save();
        });
    }
}
