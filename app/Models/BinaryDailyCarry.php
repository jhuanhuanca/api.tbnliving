<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BinaryDailyCarry extends Model
{
    protected $table = 'binary_daily_carry';

    protected $fillable = [
        'user_id',
        'day_key',
        'left_carry_pv',
        'right_carry_pv',
    ];

    protected function casts(): array
    {
        return [
            'day_key' => 'date',
            'left_carry_pv' => 'decimal:4',
            'right_carry_pv' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

