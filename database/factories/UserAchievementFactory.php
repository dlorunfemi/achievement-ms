<?php

namespace Database\Factories;

use App\Domain\Achievements\Models\Achievement;
use App\Domain\Achievements\Models\UserAchievement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAchievement>
 */
class UserAchievementFactory extends Factory
{
    protected $model = UserAchievement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $threshold = fake()->unique()->numberBetween(1, 1000);

        return [
            'user_id' => User::factory(),
            'achievement_key' => 'purchases.'.$threshold,
            'achievement_name' => $threshold.' Purchases',
            'group_key' => 'purchases',
            'threshold' => $threshold,
            'unlocked_at' => now(),
        ];
    }

    /**
     * Snapshot a catalog achievement exactly as the unlock action would.
     */
    public function fromCatalog(Achievement $achievement): static
    {
        return $this->state(fn (): array => [
            'achievement_key' => $achievement->key,
            'achievement_name' => $achievement->name,
            'group_key' => $achievement->group_key,
            'threshold' => $achievement->threshold,
        ]);
    }
}
