<?php

use App\Domain\Achievements\Actions\BuildUserProgression;
use App\Domain\Achievements\Events\AchievementUnlocked;
use App\Domain\Achievements\Events\BadgeUnlocked;
use App\Domain\Achievements\Listeners\UnlockAchievementsForPurchase;
use App\Domain\Achievements\Models\Achievement;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Events\CashbackPaid;
use App\Domain\Cashback\Models\Cashback;
use App\Domain\Ordering\Actions\CompleteOrder;
use App\Domain\Ordering\Events\OrderCompleted;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Product;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->gateway = fakeGateway();
    $this->user = userWithPayoutAccount();
});

it('carries a purchase all the way through to money in the user\'s account', function () {
    completePurchases($this->user, 1);

    expect($this->user->achievements()->pluck('achievement_name')->all())->toBe(['First Purchase'])
        ->and($this->user->badges()->pluck('badge_name')->all())->toBe(['Beginner'])
        ->and($this->gateway->transferCount())->toBe(1)
        ->and(Cashback::first()->status)->toBe(PayoutStatus::Paid)
        ->and(Cashback::first()->amount_minor)->toBe(30_000);
});

it('publishes every domain event in the chain', function () {
    // Faking these events would stop their listeners, and with them the chain, so
    // the real dispatcher is observed instead. Order is deliberately not asserted:
    // wildcard listeners run after an event's concrete listeners, so OrderCompleted
    // is only recorded once its whole downstream chain has already finished.
    $dispatched = [];
    Event::listen('*', function (string $event) use (&$dispatched): void {
        $dispatched[] = $event;
    });

    completePurchases($this->user, 1);

    expect($dispatched)->toContain(
        OrderCompleted::class,
        AchievementUnlocked::class,
        BadgeUnlocked::class,
        CashbackPaid::class,
    );
});

it('pays one reward per badge across a long purchase history', function () {
    completePurchases($this->user, 10);

    // 3 achievements (1, 5, 10) earns Beginner (1) and nothing further, since
    // Intermediate needs 4 achievements.
    expect($this->user->achievements()->count())->toBe(3)
        ->and($this->user->badges()->count())->toBe(1)
        ->and(Cashback::count())->toBe(1)
        ->and($this->gateway->transferCount())->toBe(1);
});

it('reports the user\'s standing exactly as the brief specifies', function () {
    completePurchases($this->user, 5);

    expect(app(BuildUserProgression::class)->handle($this->user)->toArray())->toBe([
        'unlocked_achievements' => ['First Purchase', '5 Purchases'],
        'next_available_achievements' => [
            'Shopped on 3 Days',
            '10 Purchases',
            '₦250,000 Spent',
            '3 Different Products',
        ],
        'current_badge' => 'Beginner',
        'next_badge' => 'Intermediate',
        'remaining_to_unlock_next_badge' => 2,
    ]);
});

it('offers only the next achievement of each group', function () {
    Achievement::factory()->forGroup('referrals', 1, 'First Referral')->create();
    Achievement::factory()->forGroup('referrals', 5, 'Five Referrals')->create();

    completePurchases($this->user, 1);

    expect(app(BuildUserProgression::class)->handle($this->user)->nextAvailableAchievements)
        ->toBe([
            'Shopped on 3 Days',
            '5 Purchases',
            'First Referral',
            '₦250,000 Spent',
            '3 Different Products',
        ]);
});

it('does nothing for a cancelled purchase', function () {
    $product = Product::factory()->create();
    Order::factory()->cancelled()->for($this->user)->for($product)->count(3)->create();

    expect($this->user->achievements()->count())->toBe(0)
        ->and(Cashback::count())->toBe(0)
        ->and($this->gateway->transferCount())->toBe(0);
});

it('leaves the user unpaid but the achievements intact when the provider is down', function () {
    $this->gateway->alwaysFail('Provider unavailable');

    completePurchases($this->user, 1);

    expect($this->user->achievements()->count())->toBe(1)
        ->and($this->user->badges()->count())->toBe(1)
        ->and(Cashback::first()->status)->toBe(PayoutStatus::Failed)
        ->and(Cashback::first()->failure_reason)->toBe('Provider unavailable');
});

it('still unlocks achievements for a user who has no bank account yet', function () {
    $user = User::factory()->create();

    completePurchases($user, 1);

    expect($user->achievements()->count())->toBe(1)
        ->and($user->badges()->count())->toBe(1)
        ->and(Cashback::first()->status)->toBe(PayoutStatus::Failed);
});

it('keeps each user\'s progress and payouts separate', function () {
    $other = userWithPayoutAccount();

    completePurchases($this->user, 5);
    completePurchases($other, 1);

    expect($this->user->achievements()->count())->toBe(2)
        ->and($other->achievements()->count())->toBe(1)
        ->and(Cashback::where('user_id', $this->user->getKey())->count())->toBe(1)
        ->and(Cashback::where('user_id', $other->getKey())->count())->toBe(1);
});

it('queues the payout rather than blocking the request that completed the order', function () {
    Queue::fake();

    completePurchases($this->user, 1);

    Queue::assertPushed(CallQueuedListener::class,
        fn ($job) => $job->class === UnlockAchievementsForPurchase::class);
});

it('is safe to replay the whole chain', function () {
    $product = Product::factory()->create();
    $order = Order::factory()->for($this->user)->for($product)->create();

    foreach (range(1, 5) as $ignored) {
        app(CompleteOrder::class)->handle($order->fresh());
    }

    expect($this->user->achievements()->count())->toBe(1)
        ->and($this->user->badges()->count())->toBe(1)
        ->and(Cashback::count())->toBe(1)
        ->and($this->gateway->transferCount())->toBe(1);
});
