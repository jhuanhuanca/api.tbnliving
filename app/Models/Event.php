<?php

namespace App\Models;

use App\Support\EventFlyerStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Event extends Model
{
    public const KIND_VIRTUAL = 'virtual';

    public const KIND_PRESENCIAL = 'presencial';

    public const PLATFORM_YOUTUBE = 'youtube';

    public const PLATFORM_ZOOM = 'zoom';

    protected $fillable = [
        'uuid',
        'kind',
        'platform',
        'name',
        'description',
        'speaker',
        'starts_at',
        'ends_at',
        'virtual_url',
        'address',
        'entry_cost',
        'details',
        'flyer_path',
        'flyer_mime',
        'flyer_original_name',
        'estado',
    ];

    protected $hidden = [
        'flyer_path',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'entry_cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }
        });
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function isVirtual(): bool
    {
        return $this->kind === self::KIND_VIRTUAL;
    }

    public function isPresencial(): bool
    {
        return $this->kind === self::KIND_PRESENCIAL;
    }

    public function requiresPaidEntry(): bool
    {
        return $this->isPresencial() && bccomp((string) ($this->entry_cost ?? '0'), '0', 2) === 1;
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'platform' => $this->platform,
            'name' => $this->name,
            'description' => $this->description,
            'speaker' => $this->speaker,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'virtual_url' => $this->virtual_url,
            'address' => $this->address,
            'entry_cost' => $this->entry_cost,
            'details' => $this->details,
            'estado' => $this->estado,
            'has_flyer' => EventFlyerStorage::existsFor($this),
            'flyer_url' => EventFlyerStorage::existsFor($this)
                ? url('/api/v1/public/events/'.$this->id.'/flyer')
                : null,
            'requires_payment' => $this->requiresPaidEntry(),
        ];
    }
}
