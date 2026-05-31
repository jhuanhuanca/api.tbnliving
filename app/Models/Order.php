<?php

namespace App\Models;

use App\Events\OrderCompleted;
use App\Services\InvoiceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Order extends Model
{
    protected $fillable = [
        'uuid',
        'reference',
        'user_id',
        'tipo',
        'cantidad',
        'total',
        'total_pv',
        'estado',
        'completed_at',
        'payment_method',
        'payment_confirmed_at',
        'payment_confirmed_by',
        'payment_admin_notes',
        'delivery_mode',
        'shipping_departamento',
        'shipping_ciudad',
        'shipping_direccion',
        'shipping_cost',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'total_pv' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'completed_at' => 'datetime',
            'payment_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function commissionRun(): HasOne
    {
        return $this->hasOne(OrderCommissionRun::class);
    }

    /**
     * PV comisionable del pedido.
     * Nota: en `order_items`, `pv_points` ya es el PV total de la línea (unitario × cantidad), igual que al crear el pedido.
     */
    public function commissionablePvTotal(): string
    {
        $this->loadMissing('items');
        $sum = '0';
        foreach ($this->items as $item) {
            $sum = bcadd($sum, $item->effectiveCommissionablePv(), 4);
        }

        return bcadd($sum, '0', 2);
    }

    /**
     * PV de paquetes personales del pedido (inicio / recompra fundador).
     */
    public function personalPackagePvTotal(): string
    {
        $this->loadMissing('items');
        $sum = '0';
        foreach ($this->items as $item) {
            if (! $item->isPackageActivationLine()) {
                continue;
            }
            $sum = bcadd($sum, $item->effectiveCommissionablePv(), 4);
        }

        return bcadd($sum, '0', 2);
    }

    /** PV de productos del catálogo en este pedido (solo líneas producto). */
    public function personalProductPvTotal(): string
    {
        $this->loadMissing('items');
        $sum = '0';
        foreach ($this->items as $item) {
            if (! $item->isProductLine()) {
                continue;
            }
            $sum = bcadd($sum, $item->effectiveCommissionablePv(), 4);
        }

        return bcadd($sum, '0', 2);
    }

    /**
     * PV del pedido que cuenta para activación mensual:
     * - Paquetes (inicio / fundador): siempre.
     * - Productos: solo si en esta compra el PV de productos ≥ umbral de activación del socio.
     */
    public function monthlyActivationPvTotal(string $activationThreshold): string
    {
        $threshold = bcadd($activationThreshold, '0', 2);
        $packagePv = $this->personalPackagePvTotal();
        $productPv = $this->personalProductPvTotal();

        $total = bcadd($packagePv, '0', 2);
        if (bccomp($productPv, $threshold, 2) >= 0) {
            $total = bcadd($total, $productPv, 2);
        }

        return bcadd($total, '0', 2);
    }

    /**
     * Suma PV comisionable de pedidos completados en un rango de fechas (para calificación mensual / onboarding).
     */
    public static function sumCommissionablePvForUserBetween(int $userId, Carbon $start, Carbon $end): string
    {
        $sum = '0';
        /** @var \Illuminate\Database\Eloquent\Collection<int, self> $orders */
        $orders = static::query()
            ->where('user_id', $userId)
            ->where('estado', 'completado')
            ->whereBetween('completed_at', [$start, $end])
            ->with('items')
            ->get();

        foreach ($orders as $order) {
            /** @var self $order */
            $sum = bcadd($sum, $order->commissionablePvTotal(), 2);
        }

        return bcadd($sum, '0', 2);
    }

    /**
     * PV de activación mensual en el periodo (paquetes + productos en compra única ≥ umbral).
     */
    public static function sumMonthlyActivationPvForUserBetween(
        int $userId,
        Carbon $start,
        Carbon $end,
        string $activationThreshold
    ): string {
        $sum = '0';
        /** @var \Illuminate\Database\Eloquent\Collection<int, self> $orders */
        $orders = static::query()
            ->where('user_id', $userId)
            ->where('estado', 'completado')
            ->whereBetween('completed_at', [$start, $end])
            ->with('items')
            ->get();

        foreach ($orders as $order) {
            /** @var self $order */
            $sum = bcadd($sum, $order->monthlyActivationPvTotal($activationThreshold), 2);
        }

        return bcadd($sum, '0', 2);
    }

    /** @deprecated Use sumMonthlyActivationPvForUserBetween() */
    public static function sumPersonalPackagePvForUserBetween(int $userId, Carbon $start, Carbon $end): string
    {
        return static::sumMonthlyActivationPvForUserBetween(
            $userId,
            $start,
            $end,
            (string) config('mlm.career.direct_active_min_pv', '50')
        );
    }

    public function markCompleted(): void
    {
        if ($this->estado === 'completado') {
            return;
        }
        if (! in_array($this->estado, ['pendiente', 'pendiente_pago'], true)) {
            return;
        }
        $this->estado = 'completado';
        $this->completed_at = now();
        $this->save();
        $order = $this->fresh(['items.product', 'items.package', 'user.sponsor', 'user.rank']);
        if ($order) {
            // Factura local de inmediato (no depende de la cola de MLM).
            app(InvoiceService::class)->emitirDesdeOrdenSiNoExiste($order);
            OrderCompleted::dispatch($order);
        }
    }
}
