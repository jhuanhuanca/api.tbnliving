<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'price',
        'pv_points',
        'commissionable_amount',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'pv_points' => 'decimal:2',
            'commissionable_amount' => 'decimal:2',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function commissionableValue(): string
    {
        return (string) ($this->commissionable_amount ?? $this->price);
    }
}
