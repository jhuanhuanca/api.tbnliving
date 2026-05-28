<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Withdrawal extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APROBADO = 'aprobado';

    public const ESTADO_RECHAZADO = 'rechazado';

    public const ESTADO_COMPLETADO = 'completado';

    /** Alias API (inglés) → estado persistido. */
    public const STATUS_ALIASES = [
        'pending' => self::ESTADO_PENDIENTE,
        'approved' => self::ESTADO_APROBADO,
        'rejected' => self::ESTADO_RECHAZADO,
        'paid' => self::ESTADO_COMPLETADO,
    ];

    protected $fillable = [
        'user_id',
        'withdrawal_otp_id',
        'monto',
        'fee',
        'net_amount',
        'estado',
        'notas_usuario',
        'notas_admin',
        'rejected_reason',
        'processed_by',
        'processed_at',
        'idempotency_key',
        'created_ip',
        'created_device',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function getStatusAttribute(): string
    {
        return match ($this->estado) {
            self::ESTADO_APROBADO => 'approved',
            self::ESTADO_RECHAZADO => 'rejected',
            self::ESTADO_COMPLETADO => 'paid',
            default => 'pending',
        };
    }

    public function otp(): BelongsTo
    {
        return $this->belongsTo(WithdrawalOtp::class, 'withdrawal_otp_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
