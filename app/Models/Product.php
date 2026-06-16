<?php

namespace App\Models;

use App\Support\ProductImageStorage;
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
        'image_path',
        'image_mime',
        'image_original_name',
        'category_id',
        'pv_points',
        'estado',
    ];

    protected $hidden = [
        'image_path',
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

    /**
     * URL pública para catálogo: imagen almacenada en API o legacy image_url.
     */
    public function resolveImageUrl(): ?string
    {
        $stored = ProductImageStorage::publicUrl($this);
        if ($stored) {
            return $stored;
        }

        $legacy = $this->getAttributes()['image_url'] ?? null;

        return is_string($legacy) && trim($legacy) !== '' ? trim($legacy) : null;
    }
}
