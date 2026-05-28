<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class VerifyWithdrawOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'code' => ['sometimes', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }

    public function resolvedOtp(): string
    {
        return trim((string) $this->input('otp', $this->input('code')));
    }
}
