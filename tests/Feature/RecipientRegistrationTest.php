<?php

use App\Domain\Achievements\Models\UserBadge;
use App\Domain\Cashback\Actions\PayBadgeCashback;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Models\User;

beforeEach(function () {
    $this->gateway = fakeGateway();
    $this->user = userWithPayoutAccount();
});

function payABadgeFor(User $user): void
{
    app(PayBadgeCashback::class)->handle(UserBadge::factory()->for($user)->create());
}

it('registers the account with the provider before the first payout', function () {
    payABadgeFor($this->user);

    expect($this->gateway->recipientCount())->toBe(1)
        ->and($this->user->defaultPayoutAccount()->recipientTokenFor('fake'))->toStartWith('fake_rcp_');
});

it('sends the registered token along with the transfer', function () {
    payABadgeFor($this->user);

    $token = $this->user->defaultPayoutAccount()->recipientTokenFor('fake');

    expect($this->gateway->lastTransfer()->recipient->providerToken)->toBe($token);
});

/*
 * The whole point of persisting the token: paying a user their second badge should
 * not send the same bank details to the provider for registration all over again.
 */
it('registers once and reuses the token for every later payout', function () {
    payABadgeFor($this->user);
    payABadgeFor($this->user);
    payABadgeFor($this->user);

    expect($this->gateway->recipientCount())->toBe(1)
        ->and($this->gateway->transferCount())->toBe(3);
});

it('keeps a token per provider, since one provider cannot use another\'s', function () {
    $account = $this->user->defaultPayoutAccount();
    $account->rememberRecipientToken('paystack', 'RCP_paystack');

    payABadgeFor($this->user);

    expect($account->refresh()->recipientTokenFor('paystack'))->toBe('RCP_paystack')
        ->and($account->recipientTokenFor('fake'))->not->toBe('RCP_paystack');
});

it('fails the payout without sending money when the provider refuses the account', function () {
    $this->gateway->failRegistration('Invalid account number');

    payABadgeFor($this->user);

    $cashback = $this->user->cashbacks()->sole();

    expect($cashback->status)->toBe(PayoutStatus::Failed)
        ->and($cashback->failure_reason)->toBe('Invalid account number')
        ->and($this->gateway->transferCount())->toBe(0);
});

it('registers nothing for a user with no account on file', function () {
    payABadgeFor(User::factory()->create());

    expect($this->gateway->recipientCount())->toBe(0);
});
