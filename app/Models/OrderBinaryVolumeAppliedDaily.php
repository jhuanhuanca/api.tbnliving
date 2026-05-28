<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderBinaryVolumeAppliedDaily extends Model
{
    protected $table = 'order_binary_volume_applied_daily';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'day_key',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'day_key' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

