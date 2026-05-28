<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletPaymentToken extends Model
{
    protected $fillable = [
        'owner_user_id',
        'token_hash',
        'expires_at',
        'used_at',
        'used_by_user_id',
        'used_order_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}

