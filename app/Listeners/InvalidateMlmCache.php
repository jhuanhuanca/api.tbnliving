<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\WithdrawalStatusChanged;
use App\Services\Cache\MlmCacheInvalidator;

class InvalidateMlmCache
{
    public function __construct(
        protected MlmCacheInvalidator $invalidator,
    ) {}

    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $order = $event->order;
        $this->invalidator->onOrderCompleted($order?->user_id ? (int) $order->user_id : null);
    }

    public function handleWithdrawalStatusChanged(WithdrawalStatusChanged $event): void
    {
        $userId = $event->withdrawal->user_id ? (int) $event->withdrawal->user_id : null;
        $this->invalidator->onWithdrawalChanged($userId);
    }
}
