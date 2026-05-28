<?php

namespace App\Jobs;

use App\Services\LeadershipBonusAccrualService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Calcula y acredita bono de liderazgo para un mes YYYY-MM.
 */
class CalculateLeadershipMonthlyBonusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $monthKey)
    {
        $this->onQueue(config('mlm.queues.residual', 'default'));
    }

    public function handle(LeadershipBonusAccrualService $service): void
    {
        $service->processMonth($this->monthKey);
    }
}

