<?php

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Withdrawal */
class WithdrawalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (string) $this->monto,
            'monto' => (string) $this->monto,
            'fee' => (string) ($this->fee ?? '0.00'),
            'net_amount' => (string) ($this->net_amount ?? $this->monto),
            'status' => $this->status,
            'estado' => $this->estado,
            'notes' => $this->notas_usuario,
            'notas_usuario' => $this->notas_usuario,
            'rejected_reason' => $this->rejected_reason ?? $this->notas_admin,
            'created_at' => $this->created_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
