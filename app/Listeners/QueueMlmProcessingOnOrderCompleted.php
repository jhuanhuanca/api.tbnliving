<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;

class QueueMlmProcessingOnOrderCompleted implements ShouldQueue
{
    public string $queue;

    public function __construct(
        protected OrderService $orderService
    ) {
        $this->queue = config('mlm.queues.binary', 'default');
    }

    public function handle(OrderCompleted $event): void
    {
        $order = $event->order->fresh(['items', 'user']);
        if ($order) {
            $this->orderService->procesarOrdenFinalizada($order);
        }
    }
}
