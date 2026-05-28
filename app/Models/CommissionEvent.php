<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommissionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'idempotency_key',
        'unique_hash',
        'beneficiary_user_id',
        'origin_user_id',
        'type',
        'level',
        'amount',
        'currency',
        'period_key',
        'period_type',
        'accrual_week_key',
        'wallet_credited_at',
        'order_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
            'created_at' => 'datetime',
            'wallet_credited_at' => 'datetime',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'origin_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class, 'commission_event_id');
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
