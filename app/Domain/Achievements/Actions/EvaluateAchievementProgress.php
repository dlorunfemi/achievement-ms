<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Contracts\ProgressMetric;
use App\Domain\Achievements\Events\AchievementUnlocked;
use App\Domain\Achievements\Events\BadgeUnlocked;
use App\Domain\Achievements\Models\Achievement;
use App\Domain\Achievements\Models\Badge;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Brings a user's unlocked achievements and badges up to date with their current
 * activity, firing a domain event for every new unlock.
 *
 * This is deliberately idempotent and level-based rather than incremental: it asks
 * each metric where the user stands now and unlocks everything they have earned but
 * do not yet hold. Replaying the same OrderCompleted event, or running after a missed
 * event, converges on the same state instead of double-awarding.
 */
final class EvaluateAchievementProgress
{
    /**
     * Create a new class instance.
     *
     * @param  iterable<ProgressMetric>  $metrics
     */
    public function __construct(private iterable $metrics)
    {
        //
    }

    public function handle(User $user): void
    {
        // Two orders completing at once would otherwise both read the same "already
        // unlocked" set and race. The unique indexes are the last line of defence;
        // this lock keeps us from relying on them.
        Cache::lock("achievement-progress:{$user->getKey()}", 10)->block(5, function () use ($user): void {
            DB::transaction(function () use ($user): void {
                $this->unlockEarnedAchievements($user);
                $this->unlockEarnedBadges($user);
            });
        });
    }

    private function unlockEarnedAchievements(User $user): void
    {
        $alreadyHeld = $user->achievements()->pluck('achievement_key')->all();

        foreach ($this->metrics as $metric) {
            $reached = $metric->currentValueFor($user);

            $newlyEarned = Achievement::query()
                ->where('group_key', $metric->groupKey())
                ->where('threshold', '<=', $reached)
                ->whereNotIn('key', $alreadyHeld)
                ->inProgressionOrder()
                ->get();

            foreach ($newlyEarned as $achievement) {
                $userAchievement = $user->achievements()->create([
                    'achievement_key' => $achievement->key,
                    'achievement_name' => $achievement->name,
                    'group_key' => $achievement->group_key,
                    'threshold' => $achievement->threshold,
                    'unlocked_at' => now(),
                ]);

                AchievementUnlocked::dispatch($achievement->name, $user, $userAchievement);
            }
        }
    }

    /**
     * Badges are earned on the running total of unlocked achievements, regardless of
     * which group they came from.
     */
    private function unlockEarnedBadges(User $user): void
    {
        $achievementsHeld = $user->achievements()->count();
        $alreadyHeld = $user->badges()->pluck('badge_key')->all();

        $newlyEarned = Badge::query()
            ->where('threshold', '<=', $achievementsHeld)
            ->whereNotIn('key', $alreadyHeld)
            ->inProgressionOrder()
            ->get();

        foreach ($newlyEarned as $badge) {
            $userBadge = $user->badges()->create([
                'badge_key' => $badge->key,
                'badge_name' => $badge->name,
                'threshold' => $badge->threshold,
                'unlocked_at' => now(),
            ]);

            BadgeUnlocked::dispatch($badge->name, $user, $userBadge);
        }
    }
}
