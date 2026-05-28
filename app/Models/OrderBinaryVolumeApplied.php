<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderBinaryVolumeApplied extends Model
{
    protected $table = 'order_binary_volume_applied';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'week_key',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
