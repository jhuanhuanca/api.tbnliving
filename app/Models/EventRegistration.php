<?php

namespace App\Models;

use App\Support\EventRegistrationProofStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventRegistration extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'event_id',
        'cantidad',
        'unit_price',
        'total',
        'estado',
        'payment_method',
        'payment_confirmed_at',
        'payment_confirmed_by',
        'payment_admin_notes',
        'payment_proof_path',
        'payment_proof_mime',
        'payment_proof_original_name',
    ];

    protected $hidden = [
        'payment_proof_path',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EventRegistration $row) {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function hasPaymentProof(): bool
    {
        return EventRegistrationProofStorage::existsFor($this);
    }

    public function markCompleted(?int $confirmedBy = null, ?string $notes = null): void
    {
        if ($this->estado === 'completado') {
            return;
        }

        $this->forceFill([
            'estado' => 'completado',
            'payment_confirmed_at' => now(),
            'payment_confirmed_by' => $confirmedBy,
            'payment_admin_notes' => $notes ?? $this->payment_admin_notes,
        ])->save();
    }
}
