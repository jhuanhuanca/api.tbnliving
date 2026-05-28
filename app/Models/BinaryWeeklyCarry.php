<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BinaryWeeklyCarry extends Model
{
    protected $table = 'binary_weekly_carry';

    protected $fillable = [
        'user_id',
        'week_key',
        'left_carry_pv',
        'right_carry_pv',
    ];

    protected function casts(): array
    {
        return [
            'left_carry_pv' => 'decimal:2',
            'right_carry_pv' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
