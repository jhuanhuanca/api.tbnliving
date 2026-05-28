<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'price_cliente_preferente',
        'stock',
        'image_url',
        'category_id',
        'pv_points',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_cliente_preferente' => 'decimal:2',
            'pv_points' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
