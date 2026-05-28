<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class RequestWithdrawOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'monto' => ['sometimes', 'numeric', 'min:0.01'],
            'password' => ['required', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function resolvedAmount(): string
    {
        $v = $this->input('amount', $this->input('monto'));

        return bcadd((string) $v, '0', 2);
    }

    public function resolvedNotes(): ?string
    {
        $n = $this->input('notes', $this->input('notas'));

        return $n !== null && $n !== '' ? (string) $n : null;
    }
}
