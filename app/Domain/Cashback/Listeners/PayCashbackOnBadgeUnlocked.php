<?php

namespace App\Domain\Cashback\Listeners;

use App\Domain\Achievements\Events\BadgeUnlocked;
use App\Domain\Cashback\Actions\AbandonCashback;
use App\Domain\Cashback\Actions\PayBadgeCashback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function __construct(
        private PayBadgeCashback $payCashback,
        private AbandonCashback $abandonCashback,
    ) {
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
        // Every log line written while paying this badge carries the same identifiers,
        // so a payout can be traced end to end without threading them through by hand.
        Log::withContext([
            'user_id' => $event->user->getKey(),
            'badge_key' => $event->userBadge->badge_key,
            'user_badge_id' => $event->userBadge->getKey(),
        ]);

        $this->payCashback->handle($event->userBadge);
    }

    /**
     * The queue has run out of attempts.
     *
     * A payout left half-finished is money owed to a real person, so the row is
     * resolved here rather than left to be found in failed_jobs.
     */
    public function failed(BadgeUnlocked $event, Throwable $exception): void
    {
        $this->abandonCashback->handle($event->userBadge, $exception);
    }
}
