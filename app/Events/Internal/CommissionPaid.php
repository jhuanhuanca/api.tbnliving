<?php

namespace App\Events\Internal;

use App\Models\CommissionEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento interno: comisión acreditada (o devengada) para consumo analítico.
 * No es público; se usa para pipelines de sync/event-driven.
 */
class CommissionPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public CommissionEvent $event) {}
}

