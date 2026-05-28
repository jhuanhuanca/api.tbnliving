<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MemberTokenService
{
    public function issue(User $user, ?Request $request = null, string $defaultDevice = 'member-app'): string
    {
        $device = (string) ($request?->input('device_name')
            ?: $request?->header('X-Device-Name')
            ?: ($request?->userAgent() ? Str::limit((string) $request->userAgent(), 120, '') : '')
            ?: $defaultDevice);

        if (config('sanctum.revoke_other_tokens_on_login', false)) {
            $user->tokens()->delete();
        }

        return $user->createToken($device)->plainTextToken;
    }
}
