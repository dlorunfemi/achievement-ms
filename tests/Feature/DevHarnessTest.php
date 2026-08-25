<?php

use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Ordering\Models\Product;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->gateway = fakeGateway();
});

it('is registered only outside production', function () {
    expect(app()->environment('local', 'testing'))->toBeTrue()
        ->and(route('dev.users.store', absolute: false))->toBe('/dev/users');
});

describe('POST dev/users', function () {
    it('creates a user with a default payout account', function () {
        $response = $this->postJson(route('dev.users.store'))->assertCreated();

        $user = User::query()->findOrFail($response->json('user.id'));

        expect($user->defaultPayoutAccount())->not->toBeNull()
            ->and($user->defaultPayoutAccount()->is_default)->toBeTrue();
    });

    it('accepts an explicit identity and bank account', function () {
        $this->postJson(route('dev.users.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@bumpa.test',
            'bank_code' => '058',
            'bank_name' => 'Guaranty Trust Bank',
            'account_number' => '0123456789',
            'account_name' => 'Ada Lovelace',
        ])->assertCreated()->assertJsonPath('user.email', 'ada@bumpa.test')
            ->assertJsonPath('payout_account.account_number', '0123456789');
    });

    it('rejects a duplicate email', function () {
        User::factory()->create(['email' => 'ada@bumpa.test']);

        $this->postJson(route('dev.users.store'), ['email' => 'ada@bumpa.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('email');
    });

    it('links to the graded endpoint and the rest of the harness', function () {
        $response = $this->postJson(route('dev.users.store'))->assertCreated();
        $id = $response->json('user.id');

        expect($response->json('links'))->toBe([
            'achievements' => route('users.achievements', $id),
            'purchases' => route('dev.users.purchases.store', $id),
            'cashbacks' => route('dev.users.cashbacks', $id),
        ]);
    });
});

describe('POST dev/users/{user}/purchases', function () {
    it('completes a single purchase by default', function () {
        $user = userWithPayoutAccount();

        $this->postJson(route('dev.users.purchases.store', $user))
            ->assertCreated()
            ->assertJsonCount(1, 'completed_orders')
            ->assertJsonPath('completed_orders.0.status', 'completed');

        expect($user->orders()->completed()->count())->toBe(1);
    });

    it('drives the real chain, so achievements and badges unlock', function () {
        $user = userWithPayoutAccount();

        $this->postJson(route('dev.users.purchases.store', $user), ['count' => 5])
            ->assertCreated()
            ->assertJsonCount(5, 'completed_orders')
            ->assertJsonPath('progression.unlocked_achievements', ['First Purchase', '5 Purchases'])
            ->assertJsonPath('progression.current_badge', 'Beginner')
            ->assertJsonPath('progression.next_badge', 'Intermediate');
    });

    it('pays the badge cashback through the gateway', function () {
        $user = userWithPayoutAccount();

        $this->postJson(route('dev.users.purchases.store', $user), ['count' => 5])
            ->assertCreated();

        expect($user->cashbacks()->where('status', PayoutStatus::Paid)->count())->toBe(1)
            ->and($this->gateway->transfers)->toHaveCount(1);
    });

    it('reuses the existing product instead of growing the catalog', function () {
        $user = userWithPayoutAccount();
        $product = Product::factory()->create();

        $this->postJson(route('dev.users.purchases.store', $user), ['count' => 3])
            ->assertCreated()
            ->assertJsonPath('product.id', $product->getKey());

        expect(Product::query()->count())->toBe(1);
    });

    it('completes against a named product', function () {
        $user = userWithPayoutAccount();
        Product::factory()->create();
        $target = Product::factory()->create();

        $this->postJson(route('dev.users.purchases.store', $user), ['product_id' => $target->getKey()])
            ->assertCreated()
            ->assertJsonPath('product.id', $target->getKey());
    });

    it('refuses a count outside the harness bounds', function (int $count) {
        $this->postJson(route('dev.users.purchases.store', userWithPayoutAccount()), ['count' => $count])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('count');
    })->with([0, 51]);

    it('refuses a product that does not exist', function () {
        $this->postJson(route('dev.users.purchases.store', userWithPayoutAccount()), ['product_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('product_id');
    });

    it('404s for an unknown user', function () {
        $this->postJson(route('dev.users.purchases.store', 9999))->assertNotFound();
    });
});

describe('GET dev/users/{user}/cashbacks', function () {
    it('reports an empty payout history for a new user', function () {
        $this->getJson(route('dev.users.cashbacks', userWithPayoutAccount()))
            ->assertOk()
            ->assertJsonPath('unlocked_badges', [])
            ->assertJsonPath('cashbacks', [])
            ->assertJsonPath('total_paid_minor', 0)
            ->assertJsonPath('gateway', 'fake');
    });

    it('reports the badge and the transfer it triggered', function () {
        $user = userWithPayoutAccount();
        completePurchases($user, 5);

        $this->getJson(route('dev.users.cashbacks', $user))
            ->assertOk()
            ->assertJsonPath('unlocked_badges.0.badge_name', 'Beginner')
            ->assertJsonPath('cashbacks.0.status', PayoutStatus::Paid->value)
            ->assertJsonPath('cashbacks.0.amount_minor', 30_000)
            ->assertJsonPath('cashbacks.0.currency', 'NGN')
            ->assertJsonCount(1, 'cashbacks')
            ->assertJsonPath('total_paid_minor', 30_000);
    });

    it('exposes the account the money was sent to', function () {
        $user = userWithPayoutAccount();

        $this->getJson(route('dev.users.cashbacks', $user))
            ->assertOk()
            ->assertJsonPath(
                'payout_account.account_number',
                $user->defaultPayoutAccount()->account_number
            );
    });
});
