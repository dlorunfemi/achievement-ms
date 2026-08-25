<?php

namespace Database\Factories;

use App\Domain\Achievements\Models\Achievement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Achievement>
 */
class AchievementFactory extends Factory
{
    protected $model = Achievement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $threshold = fake()->unique()->numberBetween(1, 1000);

        return [
            'key' => 'purchases.'.$threshold,
            'name' => $threshold.' Purchases',
            'group_key' => 'purchases',
            'threshold' => $threshold,
        ];
    }

    /**
     * A specific rung of a progression group.
     */
    public function forGroup(string $groupKey, int $threshold, ?string $name = null): static
    {
        return $this->state(fn (): array => [
            'key' => $groupKey.'.'.$threshold,
            'name' => $name ?? $threshold.' '.ucfirst($groupKey),
            'group_key' => $groupKey,
            'threshold' => $threshold,
        ]);
    }
}
