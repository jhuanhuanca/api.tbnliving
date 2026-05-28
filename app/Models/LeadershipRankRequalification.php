<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadershipRankRequalification extends Model
{
    protected $fillable = [
        'user_id',
        'rank_id',
        'initial_qualification_month_key',
        'initial_qualification_at',
        'requalification_count',
        'last_requalified_at',
        'last_paid_month_key',
        'leadership_bonus_paid_count',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'initial_qualification_at' => 'datetime',
            'last_requalified_at' => 'datetime',
            'meta' => 'array',
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

