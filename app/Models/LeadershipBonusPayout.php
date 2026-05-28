<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadershipBonusPayout extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'rank_id',
        'month_key',
        'bonus_type',
        'qualification_type',
        'amount',
        'percentage',
        'required_pv',
        'achieved_pv',
        'rank_accumulated_pv',
        'requalification_number',
        'is_initial_payment',
        'status',
        'processed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'percentage' => 'decimal:6',
            'required_pv' => 'decimal:4',
            'achieved_pv' => 'decimal:4',
            'rank_accumulated_pv' => 'decimal:4',
            'is_initial_payment' => 'boolean',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }
}

