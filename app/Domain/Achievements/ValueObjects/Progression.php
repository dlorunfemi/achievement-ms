<?php

namespace App\Domain\Achievements\ValueObjects;

/**
 * A read-only snapshot of where a user stands, shaped exactly as the
 * users/{user}/achievements endpoint reports it.
 */
final readonly class Progression
{
    /**
     * Create a new class instance.
     *
     * @param  list<string>  $unlockedAchievements
     * @param  list<string>  $nextAvailableAchievements  At most one per achievement group.
     */
    public function __construct(
        public array $unlockedAchievements,
        public array $nextAvailableAchievements,
        public ?string $currentBadge,
        public ?string $nextBadge,
        public int $remainingToUnlockNextBadge,
    ) {}

    /**
     * @return array{
     *     unlocked_achievements: list<string>,
     *     next_available_achievements: list<string>,
     *     current_badge: string|null,
     *     next_badge: string|null,
     *     remaining_to_unlock_next_badge: int
     * }
     */
    public function toArray(): array
    {
        return [
            'unlocked_achievements' => $this->unlockedAchievements,
            'next_available_achievements' => $this->nextAvailableAchievements,
            'current_badge' => $this->currentBadge,
            'next_badge' => $this->nextBadge,
            'remaining_to_unlock_next_badge' => $this->remainingToUnlockNextBadge,
        ];
    }
}
