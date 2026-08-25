<?php

namespace App\Domain\Cashback\Listeners;

use App\Domain\Achievements\Events\BadgeUnlocked;
use App\Domain\Cashback\Actions\PayBadgeCashback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The seam between Achievements and Cashback. Queued because it calls an external
 * payment provider and must not block the request that completed the order.
 */
class PayCashbackOnBadgeUnlocked implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the queued listener may be attempted.
     */
    public int $tries = 5;

    /**
     * Create the event listener.
     */
    public function __construct(private PayBadgeCashback $payCashback)
    {
        //
    }

    /**
     * Back off between attempts so a provider outage is not hammered.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    /**
     * Handle the event.
     */
    public function handle(BadgeUnlocked $event): void
    {
        $this->payCashback->handle($event->userBadge);
    }
}
