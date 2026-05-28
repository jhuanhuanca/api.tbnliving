<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'package_id',
        'cantidad',
        'precio_unitario',
        'precio_total',
        'pv_points',
        'commissionable_pv',
        'commissionable_amount',
        'line_currency',
        'fx_rate_to_wallet',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'precio_total' => 'decimal:2',
            'pv_points' => 'decimal:2',
            'commissionable_pv' => 'decimal:4',
            'commissionable_amount' => 'decimal:4',
            'fx_rate_to_wallet' => 'decimal:8',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** Alias legacy (analytics / integraciones antiguas). */
    public function getQuantityAttribute(): int
    {
        return (int) $this->cantidad;
    }

    /** Alias legacy (analytics / integraciones antiguas). */
    public function getSubtotalAttribute(): string
    {
        return (string) $this->precio_total;
    }

    /**
     * PV comisionable efectivo de la línea (calificación mensual, fundador, residual).
     * Prioriza commissionable_pv; si falta o es 0, usa pv_points (total de línea).
     */
    public function effectiveCommissionablePv(): string
    {
        $comm = $this->commissionable_pv;
        if ($comm !== null && $comm !== '' && is_numeric($comm) && bccomp((string) $comm, '0', 4) === 1) {
            return bcadd((string) $comm, '0', 2);
        }

        return bcadd((string) ($this->pv_points ?? '0'), '0', 2);
    }

    /**
     * Paquete de socio (inicio/recompra) o paquete fundador: siempre cuenta para activación mensual.
     */
    public function isPackageActivationLine(): bool
    {
        if ($this->package_id) {
            return true;
        }

        $meta = is_array($this->meta) ? $this->meta : [];

        return ! empty($meta['founder_package']);
    }

    /** Línea de producto del catálogo (compra personal). */
    public function isProductLine(): bool
    {
        return $this->product_id !== null && ! $this->isPackageActivationLine();
    }

    /** @deprecated Use isPackageActivationLine() */
    public function countsForMonthlyActivation(): bool
    {
        return $this->isPackageActivationLine();
    }
}
