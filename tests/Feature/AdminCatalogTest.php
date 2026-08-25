<?php

use App\Domain\Achievements\Models\Achievement;
use App\Domain\Achievements\Models\Badge;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\PayoutAccount;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->gateway = fakeGateway();
});

describe('achievements', function () {
    it('lists the catalog alongside the groups that can be scored', function () {
        $this->getJson(route('admin.achievements.index'))
            ->assertOk()
            ->assertJsonPath('scorable_groups', ['loyalty', 'purchases', 'spend', 'variety'])
            ->assertJsonPath('achievements.0.group_key', 'loyalty');
    });

    it('adds a rung to an existing progression', function () {
        $this->postJson(route('admin.achievements.store'), [
            'key' => 'purchases.3',
            'name' => '3 Purchases',
            'group_key' => 'purchases',
            'threshold' => 3,
        ])->assertCreated()->assertJsonPath('achievement.key', 'purchases.3');

        expect(Achievement::query()->where('key', 'purchases.3')->exists())->toBeTrue();
    });

    it('refuses a group no metric scores, and says what to do about it', function () {
        $this->postJson(route('admin.achievements.store'), [
            'key' => 'reviews.1',
            'name' => 'First Review',
            'group_key' => 'reviews',
            'threshold' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('group_key');

        expect(Achievement::query()->where('group_key', 'reviews')->exists())->toBeFalse();
    });

    it('refuses a duplicate key', function () {
        $this->postJson(route('admin.achievements.store'), [
            'key' => 'purchases.5',
            'name' => 'Five Again',
            'group_key' => 'purchases',
            'threshold' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('key');
    });

    it('refuses a second achievement at the same threshold in a group', function () {
        $this->postJson(route('admin.achievements.store'), [
            'key' => 'purchases.five-again',
            'name' => 'Five Again',
            'group_key' => 'purchases',
            'threshold' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('threshold');
    });

    it('allows the same threshold in a different group', function () {
        $this->postJson(route('admin.achievements.store'), [
            'key' => 'variety.5',
            'name' => '5 Different Products',
            'group_key' => 'variety',
            'threshold' => 5,
        ])->assertCreated();
    });

    it('refuses a threshold of zero', function () {
        $this->postJson(route('admin.achievements.store'), [
            'key' => 'purchases.0',
            'name' => 'Nothing At All',
            'group_key' => 'purchases',
            'threshold' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('threshold');
    });

    it('removes a rung without taking it away from users who earned it', function () {
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $achievement = Achievement::query()->where('key', 'purchases.1')->sole();

        $this->deleteJson(route('admin.achievements.destroy', $achievement))->assertOk();

        expect(Achievement::query()->whereKey($achievement->getKey())->exists())->toBeFalse()
            ->and($user->achievements()->where('achievement_key', 'purchases.1')->exists())->toBeTrue();
    });

    it('leaves the endpoint contract intact after a rung is removed', function () {
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $this->deleteJson(route('admin.achievements.destroy', Achievement::query()->where('key', 'purchases.1')->sole()))
            ->assertOk();

        $this->getJson(route('users.achievements', $user))
            ->assertOk()
            ->assertJsonPath('unlocked_achievements', ['First Purchase']);
    });
});

describe('badges', function () {
    it('lists badges easiest first', function () {
        $this->getJson(route('admin.badges.index'))
            ->assertOk()
            ->assertJsonPath('badges.0.name', 'Beginner');
    });

    it('adds a badge', function () {
        $this->postJson(route('admin.badges.store'), [
            'key' => 'mythic',
            'name' => 'Mythic',
            'threshold' => 20,
        ])->assertCreated()->assertJsonPath('badge.name', 'Mythic');

        expect(Badge::query()->where('key', 'mythic')->exists())->toBeTrue();
    });

    it('refuses a badge at zero achievements', function () {
        $this->postJson(route('admin.badges.store'), [
            'key' => 'freebie',
            'name' => 'Freebie',
            'threshold' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('threshold');
    });

    it('refuses two badges at the same threshold', function () {
        $this->postJson(route('admin.badges.store'), [
            'key' => 'another-beginner',
            'name' => 'Another Beginner',
            'threshold' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('threshold');
    });

    it('removes a badge without clawing back cashback already paid', function () {
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $badge = Badge::query()->where('key', 'beginner')->sole();

        $this->deleteJson(route('admin.badges.destroy', $badge))->assertOk();

        expect($user->badges()->where('badge_key', 'beginner')->exists())->toBeTrue()
            ->and($user->cashbacks()->where('status', PayoutStatus::Paid)->count())->toBe(1);
    });
});

describe('metrics', function () {
    it('publishes the group keys an achievement may be created against', function () {
        $this->getJson(route('admin.metrics.index'))
            ->assertOk()
            ->assertJsonPath('scorable_groups', ['loyalty', 'purchases', 'spend', 'variety']);
    });
});

describe('cashbacks', function () {
    it('lists payouts with their totals', function () {
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $this->getJson(route('admin.cashbacks.index'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('total_minor', 30_000)
            ->assertJsonPath('cashbacks.0.status', PayoutStatus::Paid->value);
    });

    it('filters to the payouts stuck awaiting a provider callback', function () {
        $paidUser = userWithPayoutAccount();
        completePurchases($paidUser, 1);

        $this->gateway->alwaysPend();
        $pendingUser = userWithPayoutAccount();
        completePurchases($pendingUser, 1);

        $this->getJson(route('admin.cashbacks.index', ['status' => 'processing']))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('cashbacks.0.user_id', $pendingUser->getKey());
    });

    it('rejects a status that is not a payout status', function () {
        $this->getJson(route('admin.cashbacks.index', ['status' => 'exploded']))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('status');
    });

    it('re-drives a failed payout', function () {
        $user = User::factory()->create();
        completePurchases($user, 1);

        $cashback = $user->cashbacks()->sole();
        expect($cashback->status)->toBe(PayoutStatus::Failed);

        PayoutAccount::factory()->default()->for($user)->create();

        $this->postJson(route('admin.cashbacks.retry', $cashback))
            ->assertOk()
            ->assertJsonPath('cashback.status', PayoutStatus::Paid->value);
    });

    it('refuses to re-send a transfer that is in flight', function () {
        $this->gateway->alwaysPend();
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $cashback = $user->cashbacks()->sole();

        $this->postJson(route('admin.cashbacks.retry', $cashback))->assertStatus(409);

        expect($this->gateway->transferCount())->toBe(1);
    });

    it('reports an already paid payout without sending again', function () {
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $this->postJson(route('admin.cashbacks.retry', $user->cashbacks()->sole()))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Already paid.')
            ->assertJsonPath('code', 'payout_already_paid');

        expect($this->gateway->transferCount())->toBe(1);
    });
});
