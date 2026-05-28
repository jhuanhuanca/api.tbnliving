<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCommissionRun extends Model
{
    protected $fillable = [
        'order_id',
        'unique_hash',
        'processed_at',
        'engine_version',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
