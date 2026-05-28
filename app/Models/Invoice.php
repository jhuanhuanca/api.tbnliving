<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'numero_factura',
        'issuer_nit',
        'issuer_business_name',
        'customer_document',
        'customer_business_name',
        'authorization_code',
        'cuf',
        'electronic_invoice_status',
        'fecha_emision',
        'sub_total',
        'tax_amount',
        'tax_rate',
        'total',
        'impuestos',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'sub_total' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'total' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
