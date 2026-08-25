<?php

namespace App\Domain\Achievements\Listeners;

use App\Domain\Achievements\Actions\EvaluateAchievementProgress;
use App\Domain\Ordering\Events\OrderCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The seam between Ordering and Achievements. Ordering publishes that a purchase
 * completed; this context decides what that is worth.
 */
class UnlockAchievementsForPurchase implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the queued listener may be attempted.
     */
    public int $tries = 3;

    /**
     * Create the event listener.
     */
    public function __construct(private EvaluateAchievementProgress $evaluate)
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderCompleted $event): void
    {
        $this->evaluate->handle($event->order->user);
    }
}
