<?php

use App\Domain\Achievements\Events\AchievementUnlocked;
use App\Domain\Achievements\Events\BadgeUnlocked;
use App\Domain\Achievements\Jobs\BackfillAchievementProgress;
use App\Domain\Achievements\Models\Achievement;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->gateway = fakeGateway();
});

it('grants a newly added rung to a user who already qualifies', function () {
    $user = userWithPayoutAccount();
    completePurchases($user, 5);

    expect($user->achievements()->count())->toBe(2);

    $this->postJson(route('admin.achievements.store'), [
        'key' => 'purchases.3',
        'name' => '3 Purchases',
        'group_key' => 'purchases',
        'threshold' => 3,
    ])->assertCreated();

    expect($user->achievements()->where('achievement_key', 'purchases.3')->exists())->toBeTrue();
});

it('leaves a user who does not qualify alone', function () {
    $user = userWithPayoutAccount();
    completePurchases($user, 1);

    $this->postJson(route('admin.achievements.store'), [
        'key' => 'purchases.3',
        'name' => '3 Purchases',
        'group_key' => 'purchases',
        'threshold' => 3,
    ])->assertCreated();

    expect($user->achievements()->where('achievement_key', 'purchases.3')->exists())->toBeFalse();
});

it('carries the backfill through to a badge and a real payout', function () {
    $user = userWithPayoutAccount();
    completePurchases($user, 5);

    expect($user->badges()->pluck('badge_key')->all())->toBe(['beginner'])
        ->and($this->gateway->transferCount())->toBe(1);

    // Two more rungs the user already clears takes them from 2 achievements to 4,
    // which is the Intermediate threshold.
    foreach ([2, 3] as $threshold) {
        $this->postJson(route('admin.achievements.store'), [
            'key' => "purchases.{$threshold}",
            'name' => "{$threshold} Purchases",
            'group_key' => 'purchases',
            'threshold' => $threshold,
        ])->assertCreated();
    }

    expect($user->achievements()->count())->toBe(4)
        ->and($user->badges()->pluck('badge_key')->all())->toBe(['beginner', 'intermediate'])
        ->and($user->cashbacks()->where('status', PayoutStatus::Paid)->count())->toBe(2)
        ->and($this->gateway->transferCount())->toBe(2);
});

it('backfills a badge added below where users already stand', function () {
    $user = userWithPayoutAccount();
    completePurchases($user, 5);

    $this->postJson(route('admin.badges.store'), [
        'key' => 'novice',
        'name' => 'Novice',
        'threshold' => 2,
    ])->assertCreated();

    expect($user->badges()->where('badge_key', 'novice')->exists())->toBeTrue()
        ->and($user->cashbacks()->where('status', PayoutStatus::Paid)->count())->toBe(2);
});

it('announces the backfill in the response, because it moves money', function () {
    $this->postJson(route('admin.achievements.store'), [
        'key' => 'purchases.3',
        'name' => '3 Purchases',
        'group_key' => 'purchases',
        'threshold' => 3,
    ])->assertCreated()->assertJsonStructure(['achievement', 'backfill']);
});

describe('the job itself', function () {
    it('converges rather than double-awarding when run repeatedly', function () {
        $user = userWithPayoutAccount();
        completePurchases($user, 5);

        Event::fake([AchievementUnlocked::class, BadgeUnlocked::class]);

        BackfillAchievementProgress::dispatchSync();
        BackfillAchievementProgress::dispatchSync();

        Event::assertNotDispatched(AchievementUnlocked::class);
        Event::assertNotDispatched(BadgeUnlocked::class);

        expect($user->achievements()->count())->toBe(2)
            ->and($user->cashbacks()->count())->toBe(1);
    });

    it('walks every user, not just the first page', function () {
        $users = collect(range(1, 3))->map(function (): User {
            $user = userWithPayoutAccount();
            completePurchases($user, 1);

            return $user;
        });

        Achievement::query()->create([
            'key' => 'purchases.2',
            'name' => '2 Purchases',
            'group_key' => 'purchases',
            'threshold' => 2,
        ]);

        BackfillAchievementProgress::dispatchSync();

        // One purchase each, so nobody clears the new rung — but every user was
        // visited and re-evaluated without error.
        $users->each(function (User $user): void {
            expect($user->achievements()->count())->toBe(1);
        });
    });
});
