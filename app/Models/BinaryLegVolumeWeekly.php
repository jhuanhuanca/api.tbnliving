<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BinaryLegVolumeWeekly extends Model
{
    protected $table = 'binary_leg_volume_weekly';

    protected $fillable = [
        'parent_user_id',
        'week_key',
        'leg_position',
        'volume_pv',
    ];

    protected function casts(): array
    {
        return [
            'volume_pv' => 'decimal:2',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }
}
