<?php

namespace App\Domain\Achievements\Events;

use App\Domain\Achievements\Models\UserBadge;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per badge a user earns, and the trigger for the ₦300 cashback.
 *
 * The property names match the payload named in the assessment brief exactly
 * (badge_name, user); $userBadge is additional context — the Cashback context derives
 * its idempotency key from it, which is what makes the payout exactly-once.
 */
class BadgeUnlocked implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $badge_name,
        public User $user,
        public UserBadge $userBadge,
    ) {
        //
    }
}
