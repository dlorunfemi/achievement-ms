<?php

use App\Domain\Achievements\Events\BadgeUnlocked;
use App\Domain\Achievements\Models\UserBadge;
use App\Domain\Cashback\Actions\PayBadgeCashback;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Events\CashbackFailed;
use App\Domain\Cashback\Events\CashbackPaid;
use App\Domain\Cashback\Models\Cashback;
use App\Domain\Cashback\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->gateway = fakeGateway();
    $this->user = userWithPayoutAccount();
    $this->userBadge = UserBadge::factory()->for($this->user)->create([
        'badge_key' => 'beginner',
        'badge_name' => 'Beginner',
        'threshold' => 0,
    ]);
});

function payFor(UserBadge $userBadge): Cashback
{
    return app(PayBadgeCashback::class)->handle($userBadge);
}

it('pays the ₦300 reward for an unlocked badge', function () {
    $cashback = payFor($this->userBadge);

    expect($cashback->status)->toBe(PayoutStatus::Paid)
        ->and($cashback->amount_minor)->toBe(30_000)
        ->and($cashback->currency)->toBe('NGN')
        ->and($cashback->paid_at)->not->toBeNull()
        ->and($cashback->gateway_reference)->toStartWith('fake_')
        ->and($cashback->failure_reason)->toBeNull();
});

it('sends the transfer to the user\'s default payout account', function () {
    $account = $this->user->defaultPayoutAccount();

    payFor($this->userBadge);

    expect($this->gateway->transferCount())->toBe(1)
        ->and($this->gateway->lastTransfer()->recipient->accountNumber)->toBe($account->account_number)
        ->and($this->gateway->lastTransfer()->recipient->bankCode)->toBe($account->bank_code)
        ->and($this->gateway->lastTransfer()->amount->minorUnits)->toBe(30_000);
});

it('uses the reward amount from configuration', function () {
    config()->set('cashback.badge_reward_minor', 50_000);

    expect(payFor($this->userBadge)->amount_minor)->toBe(50_000);
});

it('records which provider moved the money', function () {
    expect(payFor($this->userBadge)->gateway)->toBe('fake');
});

it('derives an idempotency key from the user badge', function () {
    expect(payFor($this->userBadge)->idempotency_key)
        ->toBe("cashback:user-badge:{$this->userBadge->getKey()}");
});

it('counts the attempt', function () {
    expect(payFor($this->userBadge)->attempts)->toBe(1);
});

it('does not pay twice when the action runs again', function () {
    payFor($this->userBadge);
    payFor($this->userBadge);
    payFor($this->userBadge);

    expect(Cashback::count())->toBe(1)
        ->and($this->gateway->transferCount())->toBe(1)
        ->and(Cashback::first()->attempts)->toBe(1);
});

it('does not pay twice when the badge unlocked event is replayed', function () {
    BadgeUnlocked::dispatch('Beginner', $this->user, $this->userBadge);
    BadgeUnlocked::dispatch('Beginner', $this->user, $this->userBadge);
    BadgeUnlocked::dispatch('Beginner', $this->user, $this->userBadge);

    expect(Cashback::count())->toBe(1)
        ->and($this->gateway->transferCount())->toBe(1);
});

it('announces a settled payout', function () {
    Event::fake([CashbackPaid::class]);

    payFor($this->userBadge);

    Event::assertDispatched(CashbackPaid::class, fn (CashbackPaid $e) => $e->cashback->status === PayoutStatus::Paid);
});

it('fails the payout when the user has no bank account on file', function () {
    $user = User::factory()->create();
    $badge = UserBadge::factory()->for($user)->create();

    $cashback = payFor($badge);

    expect($cashback->status)->toBe(PayoutStatus::Failed)
        ->and($cashback->failure_reason)->toContain('has no payout account on file')
        ->and($this->gateway->transferCount())->toBe(0);
});

it('does not count an attempt it never made', function () {
    $badge = UserBadge::factory()->for(User::factory())->create();

    expect(payFor($badge)->attempts)->toBe(0);
});

it('records the reason when the provider declines', function () {
    $this->gateway->alwaysFail('Insufficient balance');

    $cashback = payFor($this->userBadge);

    expect($cashback->status)->toBe(PayoutStatus::Failed)
        ->and($cashback->failure_reason)->toBe('Insufficient balance')
        ->and($cashback->paid_at)->toBeNull()
        ->and($cashback->attempts)->toBe(1);
});

it('announces a failed payout', function () {
    Event::fake([CashbackFailed::class]);
    $this->gateway->alwaysFail();

    payFor($this->userBadge);

    Event::assertDispatched(CashbackFailed::class);
});

it('retries a failed payout on the same record and settles it', function () {
    $this->gateway->alwaysFail('Provider down');
    payFor($this->userBadge);

    $this->gateway->alwaysSucceed();
    $cashback = payFor($this->userBadge);

    expect(Cashback::count())->toBe(1)
        ->and($cashback->status)->toBe(PayoutStatus::Paid)
        ->and($cashback->attempts)->toBe(2)
        ->and($cashback->failure_reason)->toBeNull();
});

it('holds a transfer the provider has accepted but not settled', function () {
    $this->gateway->alwaysPend();

    $cashback = payFor($this->userBadge);

    expect($cashback->status)->toBe(PayoutStatus::Processing)
        ->and($cashback->paid_at)->toBeNull()
        ->and($cashback->gateway_reference)->toStartWith('fake_');
});

it('never re-sends a transfer that is still in flight', function () {
    $this->gateway->alwaysPend();
    payFor($this->userBadge);

    $this->gateway->alwaysSucceed();
    payFor($this->userBadge);

    // Re-sending an unsettled transfer is how a provider ends up paying twice.
    expect($this->gateway->transferCount())->toBe(1)
        ->and(Cashback::first()->status)->toBe(PayoutStatus::Processing);
});

it('pays a separate reward for each badge', function () {
    $second = UserBadge::factory()->for($this->user)->create(['badge_key' => 'intermediate', 'badge_name' => 'Intermediate']);

    payFor($this->userBadge);
    payFor($second);

    // Postgres returns SUM() over a bigint as numeric, which PDO hands back as a
    // string; the cast keeps the assertion about the amount, not the driver.
    expect(Cashback::count())->toBe(2)
        ->and($this->gateway->transferCount())->toBe(2)
        ->and((int) Cashback::sum('amount_minor'))->toBe(60_000);
});

it('links the payout to the badge and the user', function () {
    $cashback = payFor($this->userBadge);

    expect($cashback->userBadge->is($this->userBadge))->toBeTrue()
        ->and($cashback->user->is($this->user))->toBeTrue();
});

it('refuses a second payout row for the same badge at the database level', function () {
    payFor($this->userBadge);

    Cashback::factory()->create([
        'user_badge_id' => $this->userBadge->getKey(),
        'user_id' => $this->user->getKey(),
        'idempotency_key' => 'some-other-key',
    ]);
})->throws(UniqueConstraintViolationException::class);

it('refuses to reuse an idempotency key at the database level', function () {
    payFor($this->userBadge);
    $other = UserBadge::factory()->for($this->user)->create(['badge_key' => 'intermediate']);

    Cashback::factory()->create([
        'user_badge_id' => $other->getKey(),
        'user_id' => $this->user->getKey(),
        'idempotency_key' => "cashback:user-badge:{$this->userBadge->getKey()}",
    ]);
})->throws(UniqueConstraintViolationException::class);

it('pays into the account the user most recently made default', function () {
    PayoutAccount::factory()->default()->for($this->user)->create(['account_number' => '9999999999']);
    $this->user->refresh();

    payFor($this->userBadge);

    expect($this->gateway->lastTransfer()->recipient->accountNumber)->toBe('9999999999');
});
