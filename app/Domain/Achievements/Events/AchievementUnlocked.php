<?php

namespace App\Domain\Achievements\Events;

use App\Domain\Achievements\Models\UserAchievement;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per achievement a user unlocks.
 *
 * The property names match the payload named in the assessment brief exactly
 * (achievement_name, user); $userAchievement is additional context for listeners that
 * need the persisted row.
 */
class AchievementUnlocked implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $achievement_name,
        public User $user,
        public UserAchievement $userAchievement,
    ) {
        //
    }
}
