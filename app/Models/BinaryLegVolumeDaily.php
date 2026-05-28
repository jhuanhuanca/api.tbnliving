<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BinaryLegVolumeDaily extends Model
{
    protected $table = 'binary_leg_volume_daily';

    protected $fillable = [
        'parent_user_id',
        'day_key',
        'leg_position',
        'volume_pv',
    ];

    protected function casts(): array
    {
        return [
            'day_key' => 'date',
            'volume_pv' => 'decimal:4',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }
}

