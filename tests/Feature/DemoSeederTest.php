<?php

use App\Domain\Achievements\Models\UserAchievement;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Product;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\DemoSeeder;

/**
 * The seeder is called directly rather than through db:seed, so these exercise the
 * seeder's own guards instead of the artisan command's production confirmation.
 */
it('refuses to seed demo users in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    app(DemoSeeder::class)->run();

    expect(User::query()->count())->toBe(0);
});

it('refuses to seed demo cashbacks against a real payment provider', function () {
    config(['payments.default' => 'paystack']);

    app(DemoSeeder::class)->run();

    expect(User::query()->count())->toBe(0);
});

/**
 * The container entrypoint runs "migrate --seed" on every boot, and the compose
 * stack keeps its database in a named volume, so the second "docker compose up"
 * seeds a store that already exists. Re-seeding used to abort on the duplicate
 * email, which killed the app container.
 */
it('can be re-run over a store it already seeded', function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class, DemoSeeder::class]);

    $before = [
        'users' => User::query()->count(),
        'products' => Product::query()->count(),
        'orders' => Order::query()->count(),
        'achievements' => UserAchievement::query()->count(),
        'cashbacks' => Cashback::query()->count(),
    ];

    app(DemoSeeder::class)->run();

    // Replaying purchases would double an order count and push a shopper onto a
    // badge their profile never meant them to hold, so nothing may move.
    expect([
        'users' => User::query()->count(),
        'products' => Product::query()->count(),
        'orders' => Order::query()->count(),
        'achievements' => UserAchievement::query()->count(),
        'cashbacks' => Cashback::query()->count(),
    ])->toBe($before);
});

it('builds a store whose state the real chain produced', function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class, DemoSeeder::class]);

    $shopper = fn (string $email): User => User::query()->where('email', $email)->sole();

    expect(User::query()->count())->toBe(8)
        ->and(Product::query()->count())->toBe(12);

    // Signed up, bought nothing.
    expect($shopper('ada@bumpa.test')->achievements()->count())->toBe(0)
        ->and($shopper('ada@bumpa.test')->badges()->count())->toBe(0);

    // Pending and cancelled orders only: they move nothing.
    expect($shopper('fatima@bumpa.test')->orders()->count())->toBe(7)
        ->and($shopper('fatima@bumpa.test')->achievements()->count())->toBe(0);

    // The brief's worked example.
    expect($shopper('amara@bumpa.test')->orders()->completed()->count())->toBe(5)
        ->and($shopper('amara@bumpa.test')->achievements()->pluck('achievement_name')->all())
        ->toContain('First Purchase', '5 Purchases');

    // Deep enough to hold badges the purchases group alone cannot reach.
    $emeka = $shopper('emeka@bumpa.test');

    expect($emeka->achievements()->pluck('group_key')->unique()->sort()->values()->all())
        ->toBe(['loyalty', 'purchases', 'spend', 'variety'])
        ->and($emeka->badges()->count())->toBeGreaterThan(3)
        ->and($emeka->cashbacks()->where('status', PayoutStatus::Paid)->count())
        ->toBe($emeka->badges()->count());

    // A badge earned with no bank account on file still records the failed payout.
    $segun = $shopper('segun@bumpa.test');

    expect($segun->badges()->count())->toBe(1)
        ->and($segun->cashbacks()->where('status', PayoutStatus::Failed)->count())->toBe(1);
});
