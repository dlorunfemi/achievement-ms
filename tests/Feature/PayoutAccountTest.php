<?php

use App\Domain\Cashback\Models\PayoutAccount;
use App\Models\User;
use App\Payments\ValueObjects\RecipientAccount;
use Illuminate\Database\UniqueConstraintViolationException;

it('belongs to a user', function () {
    $user = User::factory()->create();
    $account = PayoutAccount::factory()->for($user)->create();

    expect($account->user->is($user))->toBeTrue()
        ->and($user->payoutAccounts()->count())->toBe(1);
});

it('has no payout account until one is registered', function () {
    expect(User::factory()->create()->defaultPayoutAccount())->toBeNull();
});

it('pays into the account marked default', function () {
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create(['account_number' => '1111111111']);
    PayoutAccount::factory()->default()->for($user)->create(['account_number' => '2222222222']);
    PayoutAccount::factory()->for($user)->create(['account_number' => '3333333333']);

    expect($user->defaultPayoutAccount()->account_number)->toBe('2222222222');
});

it('falls back to the most recently added account when none is marked default', function () {
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create(['account_number' => '1111111111']);
    PayoutAccount::factory()->for($user)->create(['account_number' => '2222222222']);

    expect($user->defaultPayoutAccount()->account_number)->toBe('2222222222');
});

it('never returns another user\'s account', function () {
    $user = User::factory()->create();
    PayoutAccount::factory()->default()->create();

    expect($user->defaultPayoutAccount())->toBeNull();
});

it('refuses to register the same account twice for one user', function () {
    $user = User::factory()->create();
    $attributes = ['bank_code' => '058', 'account_number' => '0123456789'];

    PayoutAccount::factory()->for($user)->create($attributes);
    PayoutAccount::factory()->for($user)->create($attributes);
})->throws(UniqueConstraintViolationException::class);

it('lets two users hold the same account number at different banks', function () {
    PayoutAccount::factory()->create(['bank_code' => '058', 'account_number' => '0123456789']);
    PayoutAccount::factory()->create(['bank_code' => '044', 'account_number' => '0123456789']);

    expect(PayoutAccount::count())->toBe(2);
});

it('defaults to naira and to not being the preferred account', function () {
    $account = new PayoutAccount;

    expect($account->currency)->toBe('NGN')
        ->and($account->is_default)->toBeFalse();
});

it('casts the default flag to a boolean', function () {
    expect(PayoutAccount::factory()->default()->create()->is_default)->toBeTrue();
});

it('is removed when its user is deleted', function () {
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();

    $user->delete();

    expect(PayoutAccount::count())->toBe(0);
});

it('describes itself as a transfer recipient', function () {
    $account = PayoutAccount::factory()->create([
        'bank_code' => '058',
        'bank_name' => 'Guaranty Trust Bank',
        'account_number' => '0123456789',
        'account_name' => 'Ada Lovelace',
        'currency' => 'NGN',
    ]);

    $recipient = $account->toRecipientAccount();

    expect($recipient)->toBeInstanceOf(RecipientAccount::class)
        ->and($recipient->accountNumber)->toBe('0123456789')
        ->and($recipient->bankCode)->toBe('058')
        ->and($recipient->accountName)->toBe('Ada Lovelace')
        ->and($recipient->currency)->toBe('NGN');
});

it('passes the bank code through unchanged, since codes are provider specific', function () {
    $account = PayoutAccount::factory()->create(['bank_code' => 'GTB_NG_058']);

    expect($account->toRecipientAccount()->bankCode)->toBe('GTB_NG_058');
});
