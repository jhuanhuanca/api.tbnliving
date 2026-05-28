<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const TYPE_RETENTION = 'retention';

    public const TYPE_RETENTION_RELEASE = 'retention_release';

    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'idempotency_key',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference',
        'description',
        'commission_event_id',
        'withdrawal_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function commissionEvent(): BelongsTo
    {
        return $this->belongsTo(CommissionEvent::class);
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class);
    }
}
