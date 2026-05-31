<?php

namespace App\Listeners;

use App\Events\Internal\RankUpdated;
use App\Events\Internal\UserRegistered;
use App\Events\OrderCompleted;
use App\Models\Rank;
use App\Services\Mail\MemberNotificationService;

class SendMemberNotificationMails
{
    public function __construct(
        protected MemberNotificationService $notifications,
    ) {}

    public function onUserRegistered(UserRegistered $event): void
    {
        $user = $event->user->loadMissing('sponsor', 'registrationPackage');

        $this->notifications->sendWelcome($user);

        if ($user->sponsor_id && $user->sponsor) {
            $this->notifications->sendSponsorReferralNotice($user, $user->sponsor);
        }
    }

    public function onOrderCompleted(OrderCompleted $event): void
    {
        $order = $event->order->loadMissing(['user', 'items']);
        $this->notifications->sendOrderPurchaseConfirmation($order);
    }

    public function onRankUpdated(RankUpdated $event): void
    {
        if ($event->newRankId === null) {
            return;
        }

        $user = $event->user->loadMissing('rank');
        $newRank = Rank::query()->find($event->newRankId);
        if (! $newRank) {
            return;
        }

        $oldRank = $event->oldRankId
            ? Rank::query()->find($event->oldRankId)
            : null;

        $this->notifications->sendRankAchievement($user, $newRank, $oldRank);
    }
}
