<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BinaryDailyPayout extends Model
{
    protected $table = 'binary_daily_payouts';

    protected $fillable = [
        'user_id',
        'day_key',
        'left_eff_pv',
        'right_eff_pv',
        'matched_pv',
        'daily_bonus_pv',
        'daily_bonus_bob',
        'meta',
    ];

    protected $casts = [
        'day_key' => 'date',
        'left_eff_pv' => 'decimal:4',
        'right_eff_pv' => 'decimal:4',
        'matched_pv' => 'decimal:4',
        'daily_bonus_pv' => 'decimal:6',
        'daily_bonus_bob' => 'decimal:6',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

