<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlmPeriod extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'period_type',
        'period_key',
        'status',
        'closed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
