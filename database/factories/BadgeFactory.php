<?php

namespace Database\Factories;

use App\Domain\Achievements\Models\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Badge>
 */
class BadgeFactory extends Factory
{
    protected $model = Badge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $threshold = fake()->unique()->numberBetween(1, 1000);

        return [
            'key' => 'badge-'.$threshold,
            'name' => 'Badge '.$threshold,
            'threshold' => $threshold,
        ];
    }

    public function withThreshold(int $threshold, ?string $name = null): static
    {
        return $this->state(fn (): array => [
            'key' => str($name ?? 'badge-'.$threshold)->slug()->value(),
            'name' => $name ?? 'Badge '.$threshold,
            'threshold' => $threshold,
        ]);
    }
}
