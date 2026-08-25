<?php

use App\Domain\Cashback\Actions\ReconcilePendingCashbacks;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->gateway = fakeGateway();
});

/**
 * A payout the provider accepted and then went quiet about, left alone long enough
 * for the sweep to take an interest.
 *
 * The timestamp is moved with a query rather than a save, which would touch
 * updated_at right back to now.
 */
function stuckCashback(array $attributes = []): Cashback
{
    $cashback = Cashback::factory()->processing()->create($attributes);

    Cashback::query()->whereKey($cashback->getKey())->update(['updated_at' => now()->subHour()]);

    return $cashback->refresh();
}

function reconcile(?int $minutes = null): int
{
    return app(ReconcilePendingCashbacks::class)->handle($minutes);
}

it('settles a payout the provider never called back about', function () {
    $cashback = stuckCashback();

    $this->gateway->settle($cashback->idempotency_key, 'fake_TRF');

    expect(reconcile())->toBe(1);

    expect($cashback->refresh())
        ->status->toBe(PayoutStatus::Paid)
        ->gateway_reference->toBe('fake_TRF')
        ->paid_at->not->toBeNull();
});

it('records a transfer the provider turned around and reversed', function () {
    $cashback = stuckCashback();

    $this->gateway->failTransfer($cashback->idempotency_key, 'Reversed by bank');

    expect(reconcile())->toBe(1);

    expect($cashback->refresh())
        ->status->toBe(PayoutStatus::Failed)
        ->failure_reason->toBe('Reversed by bank');
});

it('leaves a payout the provider is still working on exactly where it was', function () {
    $cashback = stuckCashback();

    expect(reconcile())->toBe(0)
        ->and($cashback->refresh()->status)->toBe(PayoutStatus::Processing);
});

/*
 * The grace period keeps the sweep from racing the webhook for every ordinary
 * transfer: a payout sent moments ago is still the callback's to resolve.
 */
it('does not touch a payout still inside the grace period', function () {
    $cashback = Cashback::factory()->processing()->create();

    $this->gateway->settle($cashback->idempotency_key);

    expect(reconcile())->toBe(0)
        ->and($cashback->refresh()->status)->toBe(PayoutStatus::Processing);
});

it('sends no money: a sweep only ever learns what already happened', function () {
    $cashback = stuckCashback();

    $this->gateway->settle($cashback->idempotency_key);
    reconcile();

    expect($this->gateway->transferCount())->toBe(0);
});

it('asks the provider that sent the payout, not whichever is configured today', function () {
    config()->set('payments.gateways.paystack', [
        'secret_key' => 'sk_test_secret',
        'base_url' => 'https://api.paystack.co',
    ]);
    config()->set('payments.http.retries', 1);

    Http::fake(['*/transfer/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'transfer_code' => 'TRF_xyz'],
    ])]);

    $cashback = stuckCashback(['gateway' => 'paystack']);

    expect(reconcile())->toBe(1)
        ->and($cashback->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($cashback->gateway_reference)->toBe('TRF_xyz');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/transfer/verify/'));
});

it('skips a payout whose provider is no longer configured, rather than throwing', function () {
    $cashback = stuckCashback(['gateway' => 'a-provider-we-dropped']);

    expect(reconcile())->toBe(0)
        ->and($cashback->refresh()->status)->toBe(PayoutStatus::Processing);
});

it('ignores payouts that have already settled', function () {
    $paid = Cashback::factory()->paid()->create();
    Cashback::query()->whereKey($paid->getKey())->update(['updated_at' => now()->subHour()]);

    $this->gateway->settle($paid->idempotency_key);

    expect(reconcile())->toBe(0);
});

it('accepts a grace period on the command line', function () {
    $cashback = Cashback::factory()->processing()->create();

    $this->gateway->settle($cashback->idempotency_key);

    // Nothing is older than an hour, so nothing is swept.
    expect(reconcile(60))->toBe(0)
        ->and(reconcile(0))->toBe(1);
});

it('is exposed as a console command', function () {
    $cashback = stuckCashback();

    $this->gateway->settle($cashback->idempotency_key);

    $this->artisan('cashbacks:reconcile')
        ->expectsOutputToContain('Settled 1 pending payout(s).')
        ->assertSuccessful();

    expect($cashback->refresh()->status)->toBe(PayoutStatus::Paid);
});

it('says so plainly when there was nothing to settle', function () {
    $this->artisan('cashbacks:reconcile')
        ->expectsOutputToContain('No pending payouts had settled.')
        ->assertSuccessful();
});
