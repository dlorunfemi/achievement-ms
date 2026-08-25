<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Models\Achievement;
use App\Domain\Achievements\Models\Badge;
use App\Domain\Achievements\ValueObjects\Progression;
use App\Models\User;

/**
 * Assembles the read model behind users/{user}/achievements.
 */
final class BuildUserProgression
{
    public function handle(User $user): Progression
    {
        $held = $user->achievements()->orderBy('unlocked_at')->orderBy('id')->get();
        $heldKeys = $held->pluck('achievement_key')->all();
        $heldCount = $held->count();

        return new Progression(
            unlockedAchievements: $held->pluck('achievement_name')->values()->all(),
            nextAvailableAchievements: $this->nextAchievementPerGroup($heldKeys),
            currentBadge: $this->currentBadgeName($user),
            nextBadge: $this->nextBadge($heldCount)?->name,
            remainingToUnlockNextBadge: $this->remainingForNextBadge($heldCount),
        );
    }

    /**
     * Only the next unearned rung of each group is offered, so a user who has taken
     * the "1 purchase" step sees "5 purchases" and not every rung above it.
     *
     * @param  list<string>  $heldKeys
     * @return list<string>
     */
    private function nextAchievementPerGroup(array $heldKeys): array
    {
        return Achievement::query()
            ->whereNotIn('key', $heldKeys)
            ->inProgressionOrder()
            ->get()
            ->groupBy('group_key')
            ->map(fn ($group): string => $group->first()->name)
            ->values()
            ->all();
    }

    private function currentBadgeName(User $user): ?string
    {
        return $user->badges()->orderByDesc('threshold')->first()?->badge_name;
    }

    private function nextBadge(int $achievementsHeld): ?Badge
    {
        return Badge::query()
            ->where('threshold', '>', $achievementsHeld)
            ->inProgressionOrder()
            ->first();
    }

    private function remainingForNextBadge(int $achievementsHeld): int
    {
        $next = $this->nextBadge($achievementsHeld);

        return $next === null ? 0 : max(0, $next->threshold - $achievementsHeld);
    }
}
