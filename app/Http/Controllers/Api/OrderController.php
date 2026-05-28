<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletPaymentToken;
use App\Support\FounderPackages;
use App\Events\Internal\OrderCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\WalletService;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $q = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.product', 'items.package', 'invoice:id,order_id,numero_factura,estado,electronic_invoice_status,cuf,total'])
            ->orderByDesc('created_at');

        $estado = $request->query('estado');
        if (is_string($estado) && $estado !== '') {
            $q->where('estado', $estado);
        }
        $tipo = $request->query('tipo');
        if (is_string($tipo) && $tipo !== '') {
            $q->where('tipo', $tipo);
        }

        $orders = $q->paginate(25);

        return response()->json($orders);
    }

    /**
     * Crear pedido y marcarlo completado (dispara comisiones vía evento + cola).
     */
    public function store(Request $request, WalletService $walletService)
    {
        $data = $request->validate([
            'tipo' => 'required|string|in:producto,paquete,mixto,fundador',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.package_id' => 'nullable|exists:packages,id',
            'items.*.founder_package' => 'nullable|string|in:basico,avanzado,profesional,fundador',
            'items.*.cantidad' => 'required|integer|min:1',
            'payment_settlement' => 'nullable|string|in:immediate,manual',
            'payment_method' => 'nullable|string|max:32', // online, wallet, wallet_token, efectivo, qr, transferencia...
            'payment_token' => 'nullable|string|max:32',
        ]);

        foreach ($data['items'] as $i => $row) {
            $n = (int) (! empty($row['product_id']))
                + (int) (! empty($row['package_id']))
                + (int) (! empty($row['founder_package']));
            if ($n !== 1) {
                throw ValidationException::withMessages([
                    "items.$i" => ['Cada ítem debe ser exactamente un producto, un paquete o un paquete fundador.'],
                ]);
            }
        }

        $immediate = ($data['payment_settlement'] ?? 'immediate') === 'immediate';
        $paymentMethod = (string) ($data['payment_method'] ?? ($immediate ? 'online' : 'pendiente'));

        $buyer = $request->user();

        if ($buyer->isPreferredCustomer()) {
            foreach ($data['items'] as $row) {
                if (! empty($row['package_id'])) {
                    return response()->json([
                        'message' => 'Los clientes preferentes solo pueden comprar productos (no paquetes de socio).',
                    ], 422);
                }
                if (! empty($row['founder_package'])) {
                    return response()->json([
                        'message' => 'Los clientes preferentes no pueden adquirir paquetes fundador.',
                    ], 422);
                }
            }
            if (($data['tipo'] ?? '') !== 'producto') {
                return response()->json([
                    'message' => 'Los clientes preferentes solo realizan pedidos de tipo producto.',
                ], 422);
            }
        }

        $hasFounderLine = false;
        foreach ($data['items'] as $row) {
            if (! empty($row['founder_package'])) {
                $hasFounderLine = true;
                break;
            }
        }

        if (! $buyer->canAccessAdminPanel() && ! $buyer->isPreferredCustomer() && $buyer->activation_paid_at === null) {
            $hasPackage = false;
            foreach ($data['items'] as $row) {
                if (! empty($row['package_id'])) {
                    $hasPackage = true;
                    break;
                }
            }
            if (! $hasPackage && $hasFounderLine) {
                return response()->json([
                    'message' => 'Para activar tu cuenta el pedido debe incluir al menos un paquete de socio. Puedes combinar paquete fundador con un paquete en el mismo pedido.',
                ], 422);
            }
            if (! $hasPackage && ! $hasFounderLine) {
                return response()->json([
                    'message' => 'Para activar tu cuenta el pedido debe incluir al menos un paquete.',
                ], 422);
            }

            // Evitar confirmaciones reiteradas: si ya existe una activación pendiente de pago, reusar y no crear otra.
            if (($data['tipo'] ?? '') === 'paquete' && ! $immediate) {
                $existing = Order::query()
                    ->where('user_id', $buyer->id)
                    ->where('estado', 'pendiente_pago')
                    ->where('tipo', 'paquete')
                    ->orderByDesc('created_at')
                    ->with(['items.package'])
                    ->first();

                if ($existing) {
                    return response()->json([
                        'message' => 'Ya tienes un pedido de activación pendiente de confirmación. Espera la validación de administración.',
                        'order' => $existing,
                    ], 409);
                }
            }
        }

        $buyerId = $request->user()->id;

        $order = DB::transaction(function () use ($data, $buyerId, $immediate, $buyer, $paymentMethod) {
            $total = '0';
            $totalPv = '0';

            $order = Order::query()->create([
                'user_id' => $buyerId,
                'tipo' => $data['tipo'],
                'cantidad' => 0,
                'total' => 0,
                'total_pv' => 0,
                'estado' => $immediate ? 'pendiente' : 'pendiente_pago',
                'payment_method' => $paymentMethod,
            ]);

            OrderCreated::dispatch($order->fresh());

            $qtySum = 0;

            foreach ($data['items'] as $row) {
                $qty = (int) $row['cantidad'];
                $qtySum += $qty;

                if (! empty($row['founder_package'])) {
                    $slug = (string) $row['founder_package'];
                    $unit = FounderPackages::priceBob($slug);
                    $pvUnit = FounderPackages::pv($slug);
                    $line = bcmul($unit, (string) $qty, 2);
                    $pvLine = bcmul($pvUnit, (string) $qty, 2);

                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => null,
                        'package_id' => null,
                        'cantidad' => $qty,
                        'precio_unitario' => $unit,
                        'precio_total' => $line,
                        'pv_points' => $pvLine,
                        'commissionable_pv' => $pvLine,
                        'commissionable_amount' => $line,
                        'meta' => [
                            'founder_package' => $slug,
                            'label' => 'Paquete Fundador ('.$slug.')',
                        ],
                    ]);

                    $total = bcadd($total, $line, 2);
                    $totalPv = bcadd($totalPv, $pvLine, 2);

                    continue;
                }

                if (! empty($row['package_id'])) {
                    $pkg = Package::query()->findOrFail($row['package_id']);
                    $unit = (string) $pkg->price;
                    $line = bcmul($unit, (string) $qty, 2);
                    $pvLine = bcmul((string) $pkg->pv_points, (string) $qty, 2);

                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => null,
                        'package_id' => $pkg->id,
                        'cantidad' => $qty,
                        'precio_unitario' => $unit,
                        'precio_total' => $line,
                        'pv_points' => $pvLine,
                        'meta' => null,
                    ]);

                    $total = bcadd($total, $line, 2);
                    $totalPv = bcadd($totalPv, $pvLine, 2);
                } else {
                    $prod = Product::query()->findOrFail($row['product_id']);
                    if ($buyer->isPreferredCustomer()) {
                        $clienteUnit = $prod->price_cliente_preferente !== null
                            ? (string) $prod->price_cliente_preferente
                            : (string) $prod->price;
                        $socioUnit = bcadd((string) $prod->price, '0', 2);
                        $unit = bcadd($clienteUnit, '0', 2);
                        $meta = [
                            'preferred_customer_line' => true,
                            'precio_socio_unit' => $socioUnit,
                            'precio_cliente_unit' => $unit,
                        ];
                    } else {
                        $unit = (string) $prod->price;
                        $meta = null;
                    }
                    $line = bcmul($unit, (string) $qty, 2);
                    $pvLine = bcmul((string) $prod->pv_points, (string) $qty, 2);

                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => $prod->id,
                        'package_id' => null,
                        'cantidad' => $qty,
                        'precio_unitario' => $unit,
                        'precio_total' => $line,
                        'pv_points' => $pvLine,
                        'meta' => $meta,
                    ]);

                    $total = bcadd($total, $line, 2);
                    $totalPv = bcadd($totalPv, $pvLine, 2);
                }
            }

            $order->update([
                'cantidad' => $qtySum,
                'total' => $total,
                'total_pv' => $totalPv,
            ]);

            return $order->fresh(['items', 'invoice']);
        });

        if ($immediate) {
            // Pago inmediato: si es con billetera, debitar antes de completar.
            if (in_array($paymentMethod, ['wallet', 'wallet_token'], true)) {
                $payer = $buyer;
                $tokenRow = null;

                if ($paymentMethod === 'wallet_token') {
                    $plain = strtoupper(trim((string) ($data['payment_token'] ?? '')));
                    if ($plain === '') {
                        return response()->json(['message' => 'Token de usuario requerido para pagar con billetera de otro socio.'], 422);
                    }

                    $hash = hash('sha256', $plain);
                    $tokenRow = WalletPaymentToken::query()
                        ->where('token_hash', $hash)
                        ->first();
                    if (! $tokenRow) {
                        return response()->json(['message' => 'Token inválido.'], 422);
                    }
                    if ($tokenRow->used_at !== null) {
                        return response()->json(['message' => 'Token ya fue usado.'], 422);
                    }
                    if ($tokenRow->expires_at && $tokenRow->expires_at->isPast()) {
                        return response()->json(['message' => 'Token expiró. Genera uno nuevo (dura 10 minutos).'], 422);
                    }

                    $payer = User::query()->find((int) $tokenRow->owner_user_id);
                    if (! $payer) {
                        return response()->json(['message' => 'No se encontró el propietario del token.'], 422);
                    }
                }

                // Debitar saldo del pagador.
                $amount = bcadd((string) ($order->total ?? '0'), '0', 2);
                $idk = "walletpay:order:{$order->id}:payer:{$payer->id}";
                $walletService->debitarSiSaldoSuficiente($payer, $amount, $idk, [
                    'order_id' => $order->id,
                    'buyer_user_id' => $buyer->id,
                    'payment_method' => $paymentMethod,
                ]);

                // Marcar token como usado (si aplica) + confirmar pago en pedido.
                DB::transaction(function () use ($order, $payer, $buyer, $paymentMethod, $tokenRow) {
                    if ($tokenRow) {
                        $tokenRow->forceFill([
                            'used_at' => now(),
                            'used_by_user_id' => $buyer->id,
                            'used_order_id' => $order->id,
                        ])->save();
                    }
                    $order->forceFill([
                        'payment_confirmed_at' => now(),
                        'payment_confirmed_by' => $payer->id,
                        'payment_admin_notes' => $paymentMethod === 'wallet'
                            ? 'Pago con billetera'
                            : 'Pago con token de usuario (billetera)',
                        'payment_method' => $paymentMethod,
                    ])->save();
                });
            }

            $order->markCompleted();

            $order->load(['items.package', 'items.product', 'invoice']);
            /** @var User $buyer */
            $buyer = $request->user()->fresh();
            if (! $buyer->canAccessAdminPanel() && ! $buyer->isPreferredCustomer()) {
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
        } else {
            $order->load(['items.package', 'items.product']);
        }

        return $order->fresh(['items', 'invoice']);
    }
}
