<?php

use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\PayoutAccount;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->gateway = fakeGateway();
});

$validAccount = [
    'bank_code' => '058',
    'bank_name' => 'Guaranty Trust Bank',
    'account_number' => '0123456789',
    'account_name' => 'Ada Lovelace',
];

it('registers an account and makes it the one payouts go to', function () use ($validAccount) {
    $user = User::factory()->create();

    $this->postJson(route('users.payout-account.store', $user), $validAccount)
        ->assertCreated()
        ->assertJsonPath('bank_code', '058')
        ->assertJsonPath('account_number', '0123456789')
        ->assertJsonPath('is_default', true);

    expect($user->defaultPayoutAccount()->account_number)->toBe('0123456789');
});

it('defaults the currency to naira', function () use ($validAccount) {
    $this->postJson(route('users.payout-account.store', User::factory()->create()), $validAccount)
        ->assertCreated()
        ->assertJsonPath('currency', 'NGN');
});

it('updates the existing row when the same account is registered again', function () use ($validAccount) {
    $user = User::factory()->create();

    $this->postJson(route('users.payout-account.store', $user), $validAccount)->assertCreated();

    $this->postJson(route('users.payout-account.store', $user), [
        ...$validAccount,
        'account_name' => 'Ada King Lovelace',
    ])->assertOk()->assertJsonPath('account_name', 'Ada King Lovelace');

    expect($user->payoutAccounts()->count())->toBe(1);
});

it('demotes the previous default when a second account takes over', function () use ($validAccount) {
    $user = User::factory()->create();
    $first = PayoutAccount::factory()->default()->for($user)->create();

    $this->postJson(route('users.payout-account.store', $user), $validAccount)->assertCreated();

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($user->payoutAccounts()->where('is_default', true)->count())->toBe(1);
});

// A user whose only account is not the default would be unpayable.
it('makes the first account default even when the caller says otherwise', function () use ($validAccount) {
    $user = User::factory()->create();

    $this->postJson(route('users.payout-account.store', $user), [...$validAccount, 'is_default' => false])
        ->assertCreated()
        ->assertJsonPath('is_default', true);
});

it('keeps a second account off the default when asked', function () use ($validAccount) {
    $user = User::factory()->create();
    $first = PayoutAccount::factory()->default()->for($user)->create();

    $this->postJson(route('users.payout-account.store', $user), [...$validAccount, 'is_default' => false])
        ->assertCreated()
        ->assertJsonPath('is_default', false);

    expect($user->defaultPayoutAccount()->is($first))->toBeTrue();
});

it('requires the fields a transfer cannot be made without', function (string $field) use ($validAccount) {
    $incomplete = $validAccount;
    unset($incomplete[$field]);

    $this->postJson(route('users.payout-account.store', User::factory()->create()), $incomplete)
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor($field);
})->with(['bank_code', 'bank_name', 'account_number', 'account_name']);

it('rejects a currency that is not a three letter code', function () use ($validAccount) {
    $this->postJson(route('users.payout-account.store', User::factory()->create()), [
        ...$validAccount,
        'currency' => 'NAIRA',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('currency');
});

it('accepts the POST without a CSRF token, since there is no session to protect', function () use ($validAccount) {
    $this->post(route('users.payout-account.store', User::factory()->create()), $validAccount)
        ->assertCreated();
});

it('404s for an unknown user', function () use ($validAccount) {
    $this->postJson(route('users.payout-account.store', 9999), $validAccount)->assertNotFound();
});

describe('reading it back', function () {
    it('returns the account payouts go to', function () {
        $user = userWithPayoutAccount();

        $this->getJson(route('users.payout-account.show', $user))
            ->assertOk()
            ->assertJsonPath('account_number', $user->defaultPayoutAccount()->account_number)
            ->assertJsonPath('is_default', true);
    });

    it('404s when the user has no account on file', function () {
        $this->getJson(route('users.payout-account.show', User::factory()->create()))
            ->assertNotFound();
    });
});

describe('retrying what failed for want of an account', function () use ($validAccount) {
    it('pays a cashback that failed because the user had nowhere to be paid', function () use ($validAccount) {
        $user = User::factory()->create();

        completePurchases($user, 1);

        $cashback = $user->cashbacks()->sole();
        expect($cashback->status)->toBe(PayoutStatus::Failed)
            ->and($this->gateway->transferCount())->toBe(0);

        $this->postJson(route('users.payout-account.store', $user), $validAccount)->assertCreated();

        expect($cashback->refresh()->status)->toBe(PayoutStatus::Paid)
            ->and($this->gateway->transferCount())->toBe(1);
    });

    it('does not re-send a cashback that was already paid', function () use ($validAccount) {
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        expect($this->gateway->transferCount())->toBe(1);

        $this->postJson(route('users.payout-account.store', $user), $validAccount)->assertCreated();

        expect($this->gateway->transferCount())->toBe(1);
    });
});

describe('resolving the account before it is stored', function () use ($validAccount) {
    it('refuses an account the bank cannot resolve', function () use ($validAccount) {
        $this->gateway->failResolution('Could not resolve account name');

        $this->postJson(route('users.payout-account.store', User::factory()->create()), $validAccount)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('account_number');

        expect(PayoutAccount::query()->count())->toBe(0);
    });

    it('stores the name the bank holds rather than the one that was typed', function () use ($validAccount) {
        $this->gateway->resolvesAs('ADA LOVELACE');

        $this->postJson(route('users.payout-account.store', User::factory()->create()), [
            ...$validAccount,
            'account_name' => 'ada l',
        ])->assertCreated()->assertJsonPath('account_name', 'ADA LOVELACE');
    });

    it('keeps the submitted name when the provider returns none', function () use ($validAccount) {
        $this->postJson(route('users.payout-account.store', User::factory()->create()), $validAccount)
            ->assertCreated()
            ->assertJsonPath('account_name', 'Ada Lovelace');
    });
});
