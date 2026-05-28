<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $fillable = [
        'name',
        'code',
        'flag_emoji',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'flag' => $this->flag_emoji,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
