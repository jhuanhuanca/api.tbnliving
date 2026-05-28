<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityLog extends Model
{
    public const ACTION_WITHDRAW_OTP_REQUEST = 'withdraw.otp.request';

    public const ACTION_WITHDRAW_OTP_VERIFY_FAIL = 'withdraw.otp.verify_fail';

    public const ACTION_WITHDRAW_OTP_VERIFY_OK = 'withdraw.otp.verify_ok';

    public const ACTION_WITHDRAW_OTP_RESEND = 'withdraw.otp.resend';

    public const ACTION_WITHDRAW_CREATED = 'withdraw.created';

    public const ACTION_WITHDRAW_APPROVED = 'withdraw.approved';

    public const ACTION_WITHDRAW_REJECTED = 'withdraw.rejected';

    protected $fillable = [
        'user_id',
        'action',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
