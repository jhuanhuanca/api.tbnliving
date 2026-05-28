<?php

namespace App\Models;

use App\Services\CareerRankService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rank extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'sort_order',
        'max_residual_generations',
        'residual_rate_override',
        'leadership_rate',
    ];

    protected function casts(): array
    {
        return [
            'residual_rate_override' => 'decimal:6',
            'leadership_rate' => 'decimal:6',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected static function booted(): void
    {
        static::saved(function () {
            CareerRankService::forgetRankSortCache();
        });
    }
}
