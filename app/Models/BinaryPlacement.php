<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BinaryPlacement extends Model
{
    public const LEG_LEFT = 'left';

    public const LEG_RIGHT = 'right';

    protected $fillable = [
        'user_id',
        'parent_user_id',
        'leg_position',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }
}
