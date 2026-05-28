<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodClosure extends Model
{
    protected $fillable = [
        'period_type',
        'period_key',
        'scope',
        'status',
        'started_at',
        'finished_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
