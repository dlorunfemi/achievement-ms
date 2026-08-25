<?php

use App\Domain\Achievements\Actions\EvaluateAchievementProgress;
use App\Domain\Achievements\Contracts\ProgressMetric;
use App\Domain\Achievements\Models\Achievement;
use App\Domain\Achievements\Models\Badge;
use App\Domain\Ordering\Actions\CompleteOrder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Product;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;

/**
 * A metric for a group nothing else scores, used to prove that a new progression
 * needs one class and some catalog rows, and no change to the unlock logic.
 */
final class ReferralCountMetric implements ProgressMetric
{
    public function groupKey(): string
    {
        return 'referrals';
    }

    public function currentValueFor(User $user): int
    {
        return 2;
    }
}

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->user = User::factory()->create();
});

/**
 * The registered metric that scores a group.
 */
function metricFor(string $groupKey): ProgressMetric
{
    return collect(app()->tagged(ProgressMetric::class))
        ->firstOrFail(fn (ProgressMetric $metric): bool => $metric->groupKey() === $groupKey);
}

/**
 * Complete one purchase of a known value, on a known product, on a known day.
 */
function completeOrderWorth(User $user, Product $product, int $amountMinor, int $daysAgo = 0): void
{
    app(CompleteOrder::class)->handle(
        Order::factory()->worth($amountMinor)->for($user)->for($product)->create([
            'placed_at' => now()->subDays($daysAgo),
        ])
    );
}

function unlockedNames(User $user): array
{
    return $user->achievements()->pluck('achievement_name')->all();
}

it('scores every group in the catalog, and seeds every group it scores', function () {
    $seeded = Achievement::query()->distinct()->pluck('group_key')->sort()->values()->all();

    $scored = collect(app()->tagged(ProgressMetric::class))
        ->map->groupKey()
        ->sort()
        ->values()
        ->all();

    expect($seeded)->toBe($scored);
});

it('keeps the badge ladder reachable: no badge asks for more than the catalog holds', function () {
    expect(Badge::query()->max('threshold'))
        ->toBeLessThanOrEqual(Achievement::query()->count());
});

it('scores total spend in whole naira, ignoring orders that never completed', function () {
    $product = Product::factory()->create();

    completeOrderWorth($this->user, $product, 20_000_050);
    Order::factory()->cancelled()->worth(90_000_000)->for($this->user)->for($product)->create();

    expect(metricFor('spend')->currentValueFor($this->user))->toBe(200_000);
});

it('unlocks a spend achievement on one large enough purchase', function () {
    completeOrderWorth($this->user, Product::factory()->create(), 25_000_000);

    expect(unlockedNames($this->user))->toContain('₦250,000 Spent');
});

it('scores variety on distinct products, not on repeat orders', function () {
    $favourite = Product::factory()->create();

    foreach (range(1, 4) as $ignored) {
        completeOrderWorth($this->user, $favourite, 10_000);
    }

    expect(metricFor('variety')->currentValueFor($this->user))->toBe(1)
        ->and(unlockedNames($this->user))->not->toContain('3 Different Products');

    foreach (Product::factory()->count(2)->create() as $other) {
        completeOrderWorth($this->user, $other, 10_000);
    }

    expect(metricFor('variety')->currentValueFor($this->user))->toBe(3)
        ->and(unlockedNames($this->user))->toContain('3 Different Products');
});

it('scores loyalty on distinct days shopped, not on orders placed', function () {
    $product = Product::factory()->create();

    foreach (range(1, 5) as $ignored) {
        completeOrderWorth($this->user, $product, 10_000);
    }

    expect(metricFor('loyalty')->currentValueFor($this->user))->toBe(1)
        ->and(unlockedNames($this->user))->not->toContain('Shopped on 3 Days');

    completeOrderWorth($this->user, $product, 10_000, daysAgo: 1);
    completeOrderWorth($this->user, $product, 10_000, daysAgo: 2);

    expect(metricFor('loyalty')->currentValueFor($this->user))->toBe(3)
        ->and(unlockedNames($this->user))->toContain('Shopped on 3 Days');
});

it('adds a whole new progression from one metric class and catalog rows', function () {
    Achievement::factory()->forGroup('referrals', 1, 'First Referral')->create();
    Achievement::factory()->forGroup('referrals', 2, 'Two Referrals')->create();
    Achievement::factory()->forGroup('referrals', 5, 'Five Referrals')->create();

    // The only wiring a new group needs: one tagged ProgressMetric.
    app()->tag([ReferralCountMetric::class], ProgressMetric::class);

    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect(unlockedNames($this->user))->toBe(['First Referral', 'Two Referrals']);
});
