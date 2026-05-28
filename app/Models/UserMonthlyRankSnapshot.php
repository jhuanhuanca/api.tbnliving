<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMonthlyRankSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'month_key',
        'rank_id',
        'rank_slug',
        'qualifying_pv',
        'leadership_streak_months',
        'binary_left_pv',
        'binary_right_pv',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'qualifying_pv' => 'decimal:2',
            'binary_left_pv' => 'decimal:2',
            'binary_right_pv' => 'decimal:2',
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
