<?php

use App\Domain\Achievements\Actions\EvaluateAchievementProgress;
use App\Domain\Achievements\Events\AchievementUnlocked;
use App\Domain\Achievements\Models\Achievement;
use App\Domain\Achievements\Models\UserAchievement;
use App\Domain\Ordering\Actions\CompleteOrder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Product;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->user = User::factory()->create();
});

it('unlocks nothing before the first purchase', function () {
    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->achievements()->count())->toBe(0);
});

it('unlocks the first purchase achievement on the first completed purchase', function () {
    completePurchases($this->user, 1);

    expect($this->user->achievements()->pluck('achievement_name')->all())
        ->toBe(['First Purchase']);
});

it('does not unlock the next rung until its threshold is reached', function (int $purchases, array $expected) {
    completePurchases($this->user, $purchases);

    expect($this->user->achievements()->pluck('achievement_name')->all())->toBe($expected);
})->with([
    [1, ['First Purchase']],
    [2, ['First Purchase']],
    [4, ['First Purchase']],
    [5, ['First Purchase', '5 Purchases']],
    [9, ['First Purchase', '5 Purchases']],
    [10, ['First Purchase', '5 Purchases', '10 Purchases']],
]);

it('counts only completed orders', function () {
    $product = Product::factory()->create();

    Order::factory()->for($this->user)->for($product)->count(4)->create();
    Order::factory()->cancelled()->for($this->user)->for($product)->count(4)->create();

    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->achievements()->count())->toBe(0);
});

it('ignores purchases made by other users', function () {
    completePurchases(User::factory()->create(), 5);

    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->achievements()->count())->toBe(0);
});

it('snapshots the catalog row so later edits do not rewrite history', function () {
    completePurchases($this->user, 1);

    Achievement::query()->where('key', 'purchases.1')->update(['name' => 'Renamed', 'threshold' => 99]);

    $held = $this->user->achievements()->first();

    expect($held->achievement_name)->toBe('First Purchase')
        ->and($held->threshold)->toBe(1)
        ->and($held->group_key)->toBe('purchases');
});

it('records when the achievement was unlocked', function () {
    completePurchases($this->user, 1);

    expect($this->user->achievements()->first()->unlocked_at)->not->toBeNull();
});

it('fires an achievement unlocked event carrying the name and the user', function () {
    Event::fake([AchievementUnlocked::class]);

    completePurchases($this->user, 1);

    Event::assertDispatched(AchievementUnlocked::class, function (AchievementUnlocked $event) {
        return $event->achievement_name === 'First Purchase'
            && $event->user->is($this->user)
            && $event->userAchievement->achievement_key === 'purchases.1';
    });
});

it('fires one event per achievement when several unlock at once', function () {
    Event::fake([AchievementUnlocked::class]);

    completePurchases($this->user, 5);

    Event::assertDispatchedTimes(AchievementUnlocked::class, 2);
});

it('does not re-unlock or re-announce an achievement already held', function () {
    completePurchases($this->user, 1);

    Event::fake([AchievementUnlocked::class]);
    app(EvaluateAchievementProgress::class)->handle($this->user);
    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->achievements()->count())->toBe(1);
    Event::assertNotDispatched(AchievementUnlocked::class);
});

it('converges on the same state when an order event is replayed', function () {
    $product = Product::factory()->create();
    $order = Order::factory()->for($this->user)->for($product)->create();
    $completeOrder = app(CompleteOrder::class);

    $completeOrder->handle($order);
    $completeOrder->handle($order->fresh());
    $completeOrder->handle($order->fresh());

    expect($this->user->achievements()->count())->toBe(1);
});

it('refuses to store the same achievement twice for one user', function () {
    completePurchases($this->user, 1);

    UserAchievement::factory()->for($this->user)->create([
        'achievement_key' => 'purchases.1',
        'achievement_name' => 'First Purchase',
        'group_key' => 'purchases',
        'threshold' => 1,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('catches a user up after missed events, rather than awarding incrementally', function () {
    // Orders completed without ever running the evaluation, as if the queue was down.
    $product = Product::factory()->create();
    Order::factory()->completed()->for($this->user)->for($product)->count(5)->create();

    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->achievements()->pluck('achievement_name')->all())
        ->toBe(['First Purchase', '5 Purchases']);
});

it('supports a new achievement group without any code change', function () {
    Achievement::factory()->forGroup('referrals', 1, 'First Referral')->create();

    completePurchases($this->user, 1);

    // No referral metric is registered, so the group simply never unlocks.
    expect($this->user->achievements()->pluck('achievement_name')->all())
        ->toBe(['First Purchase']);
});
