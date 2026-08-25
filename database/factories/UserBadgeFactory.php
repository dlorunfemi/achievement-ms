<?php

namespace Database\Factories;

use App\Domain\Achievements\Models\Badge;
use App\Domain\Achievements\Models\UserBadge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserBadge>
 */
class UserBadgeFactory extends Factory
{
    protected $model = UserBadge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $threshold = fake()->unique()->numberBetween(1, 1000);

        return [
            'user_id' => User::factory(),
            'badge_key' => 'badge-'.$threshold,
            'badge_name' => 'Badge '.$threshold,
            'threshold' => $threshold,
            'unlocked_at' => now(),
        ];
    }

    /**
     * Snapshot a catalog badge exactly as the unlock action would.
     */
    public function fromCatalog(Badge $badge): static
    {
        return $this->state(fn (): array => [
            'badge_key' => $badge->key,
            'badge_name' => $badge->name,
            'threshold' => $badge->threshold,
        ]);
    }
}
