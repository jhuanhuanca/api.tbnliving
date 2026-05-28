<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BinaryWeeklyBonus extends Model
{
    protected $table = 'binary_weekly_bonus';

    protected $fillable = [
        'user_id',
        'week_key',
        'month_key',
        'weekly_bonus_bob',
        'paid_weekly_bonus_bob',
        'accumulated_unpaid_bob',
        'final_accumulated_bob',
        'month_penalty_applied',
        'meta',
    ];

    protected $casts = [
        'weekly_bonus_bob' => 'decimal:6',
        'paid_weekly_bonus_bob' => 'decimal:6',
        'accumulated_unpaid_bob' => 'decimal:6',
        'final_accumulated_bob' => 'decimal:6',
        'month_penalty_applied' => 'boolean',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

