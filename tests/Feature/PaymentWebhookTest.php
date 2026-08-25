<?php

use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Events\CashbackFailed;
use App\Domain\Cashback\Events\CashbackPaid;
use App\Domain\Cashback\Models\Cashback;
use App\Payments\Webhooks\FakeWebhookHandler;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->secret = 'test-webhook-secret';
    config()->set('payments.gateways.fake.webhook_secret', $this->secret);
});

/**
 * Post a signed callback, signing exactly the bytes that will be sent.
 */
function postWebhook(array $payload, string $provider = 'fake', ?string $signature = null): TestResponse
{
    $body = json_encode($payload);

    return test()->postJson(route('webhooks.payments', $provider), $payload, [
        FakeWebhookHandler::SIGNATURE_HEADER => $signature ?? FakeWebhookHandler::sign($body, 'test-webhook-secret'),
    ]);
}

/**
 * A user whose badge cashback the provider accepted but has not settled.
 */
function cashbackAwaitingSettlement(): Cashback
{
    $user = userWithPayoutAccount();
    fakeGateway()->alwaysPend();

    completePurchases($user, 1);

    return $user->cashbacks()->sole();
}

describe('authentication', function () {
    it('rejects a callback with no signature', function () {
        $this->postJson(route('webhooks.payments', 'fake'), ['reference' => 'x', 'status' => 'success'])
            ->assertUnauthorized();
    });

    it('rejects a callback signed with the wrong secret', function () {
        $payload = ['reference' => 'x', 'status' => 'success'];

        postWebhook($payload, signature: FakeWebhookHandler::sign(json_encode($payload), 'not-the-secret'))
            ->assertUnauthorized();
    });

    it('rejects a signature that was valid for a different body', function () {
        postWebhook(
            ['reference' => 'tampered', 'status' => 'success'],
            signature: FakeWebhookHandler::sign(json_encode(['reference' => 'original', 'status' => 'success']), 'test-webhook-secret'),
        )->assertUnauthorized();
    });

    it('404s for a provider it has no handler for', function () {
        $this->postJson(route('webhooks.payments', 'not-a-provider'), [])->assertNotFound();
    });

    it('is reachable without a CSRF token, as a provider has none', function () {
        $this->post(route('webhooks.payments', 'fake'), [])->assertUnauthorized();
    });

    it('never marks a payout paid on an unverified callback', function () {
        $cashback = cashbackAwaitingSettlement();

        $this->postJson(route('webhooks.payments', 'fake'), [
            'reference' => $cashback->idempotency_key,
            'status' => 'success',
        ])->assertUnauthorized();

        expect($cashback->refresh()->status)->toBe(PayoutStatus::Processing);
    });
});

describe('settling a transfer', function () {
    it('moves a processing payout to paid', function () {
        $cashback = cashbackAwaitingSettlement();
        expect($cashback->status)->toBe(PayoutStatus::Processing);

        postWebhook([
            'reference' => $cashback->idempotency_key,
            'status' => 'success',
            'provider_reference' => 'fake_settled_1',
        ])->assertAccepted();

        expect($cashback->refresh()->status)->toBe(PayoutStatus::Paid)
            ->and($cashback->gateway_reference)->toBe('fake_settled_1')
            ->and($cashback->paid_at)->not->toBeNull();
    });

    it('moves a processing payout to failed, keeping the provider reason', function () {
        $cashback = cashbackAwaitingSettlement();

        postWebhook([
            'reference' => $cashback->idempotency_key,
            'status' => 'failed',
            'reason' => 'Account name mismatch',
        ])->assertAccepted();

        expect($cashback->refresh()->status)->toBe(PayoutStatus::Failed)
            ->and($cashback->failure_reason)->toBe('Account name mismatch');
    });

    it('fires CashbackPaid so the rest of the system hears about it', function () {
        $cashback = cashbackAwaitingSettlement();
        Event::fake([CashbackPaid::class, CashbackFailed::class]);

        postWebhook(['reference' => $cashback->idempotency_key, 'status' => 'success'])->assertAccepted();

        Event::assertDispatched(CashbackPaid::class);
        Event::assertNotDispatched(CashbackFailed::class);
    });
});

describe('redelivery', function () {
    it('is idempotent when the provider sends the same callback twice', function () {
        $cashback = cashbackAwaitingSettlement();
        $payload = ['reference' => $cashback->idempotency_key, 'status' => 'success'];

        postWebhook($payload)->assertAccepted();
        $paidAt = $cashback->refresh()->paid_at;

        Event::fake([CashbackPaid::class]);
        postWebhook($payload)->assertAccepted();

        Event::assertNotDispatched(CashbackPaid::class);
        expect($cashback->refresh()->paid_at->equalTo($paidAt))->toBeTrue();
    });

    it('will not un-pay a settled payout', function () {
        $cashback = cashbackAwaitingSettlement();

        postWebhook(['reference' => $cashback->idempotency_key, 'status' => 'success'])->assertAccepted();
        postWebhook(['reference' => $cashback->idempotency_key, 'status' => 'failed', 'reason' => 'late'])->assertAccepted();

        expect($cashback->refresh()->status)->toBe(PayoutStatus::Paid)
            ->and($cashback->failure_reason)->toBeNull();
    });

    it('lets a late success rescue a payout already marked failed', function () {
        $cashback = cashbackAwaitingSettlement();

        postWebhook(['reference' => $cashback->idempotency_key, 'status' => 'failed'])->assertAccepted();
        expect($cashback->refresh()->status)->toBe(PayoutStatus::Failed);

        postWebhook(['reference' => $cashback->idempotency_key, 'status' => 'success'])->assertAccepted();

        expect($cashback->refresh()->status)->toBe(PayoutStatus::Paid);
    });
});

describe('callbacks with nothing to do', function () {
    it('acknowledges a reference it does not recognise rather than erroring', function () {
        postWebhook(['reference' => 'cashback:user-badge:99999', 'status' => 'success'])
            ->assertAccepted();
    });

    it('acknowledges an event that is not a transfer outcome', function () {
        postWebhook(['reference' => 'ref', 'status' => 'something-else'])->assertAccepted();
    });

    it('acknowledges an in-flight update without touching the payout', function () {
        $cashback = cashbackAwaitingSettlement();

        postWebhook(['reference' => $cashback->idempotency_key, 'status' => 'pending'])->assertAccepted();

        expect($cashback->refresh()->status)->toBe(PayoutStatus::Processing);
    });

    it('acknowledges an empty body rather than throwing', function () {
        postWebhook([])->assertAccepted();
    });
});
