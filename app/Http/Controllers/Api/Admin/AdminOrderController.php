<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ResolvesInternalPanelActor;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderPaymentProofStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AdminOrderController extends Controller
{
    use ResolvesInternalPanelActor;
    /**
     * Pedidos (p. ej. pendientes de confirmación de pago en efectivo / QR).
     */
    public function index(Request $request)
    {
        $estado = $request->query('estado', 'pendiente_pago');
        $q = Order::query()
            ->with([
                'user:id,name,email,member_code,referral_code',
                'items.product',
                'items.package',
                'invoice:id,order_id,numero_factura,estado,electronic_invoice_status,cuf,total',
                'paymentConfirmedBy:id,name',
            ]);

        if (in_array($estado, ['pendiente_pago', 'completado'], true)) {
            $q->where('estado', $estado);
        }

        $paginator = $q->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        $paginator->getCollection()->transform(function (Order $order) {
            $order->setAttribute('has_payment_proof', OrderPaymentProofStorage::existsFor($order));

            return $order;
        });

        return $paginator;
    }

    /**
     * Comprobante de pago subido por el socio (transferencia / QR).
     */
    public function paymentProof(Order $order): Response
    {
        if (! OrderPaymentProofStorage::existsFor($order)) {
            abort(404, 'Este pedido no tiene comprobante de pago.');
        }

        return Storage::disk(OrderPaymentProofStorage::DISK)->response(
            (string) $order->payment_proof_path,
            $order->payment_proof_original_name ?: 'comprobante-pedido-'.$order->id,
            ['Content-Type' => $order->payment_proof_mime ?: 'application/octet-stream'],
        );
    }

    /**
     * Marca el pedido como pagado y dispara el mismo flujo que un pedido completado en línea.
     */
    public function confirmPayment(Request $request, Order $order)
    {
        $data = $request->validate([
            'payment_method' => 'nullable|string|max:32',
            'notas' => 'nullable|string|max:2000',
        ]);

        if ($order->estado !== 'pendiente_pago') {
            return response()->json(['message' => 'El pedido no está pendiente de pago.'], 422);
        }

        $order->forceFill([
            'payment_method' => $data['payment_method'] ?? $order->payment_method,
            'payment_confirmed_at' => now(),
            'payment_confirmed_by' => $this->resolveActor($request)->id,
            'payment_admin_notes' => $data['notas'] ?? $order->payment_admin_notes,
        ]);

        $order->markCompleted();

        $order->load(['items.package', 'items.product', 'invoice']);

        /** @var User $buyer */
        $buyer = $order->user;
        if (! $buyer->canAccessAdminPanel()) {
            foreach ($order->items as $item) {
                if ($item->package_id && $buyer->activation_paid_at === null) {
                    $buyer->forceFill([
                        'activation_paid_at' => now(),
                        'account_status' => 'active',
                    ])->save();
                    break;
                }
            }
        }

        return response()->json($order->fresh([
            'items.product',
            'items.package',
            'invoice',
            'paymentConfirmedBy',
        ]));
    }
}
