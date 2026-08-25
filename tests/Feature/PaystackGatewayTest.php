<?php

use App\Payments\Enums\TransferStatus;
use App\Payments\Gateways\PaystackGateway;
use App\Payments\ValueObjects\Money;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\TransferRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('payments.gateways.paystack', [
        'secret_key' => 'sk_test_secret',
        'base_url' => 'https://api.paystack.co',
    ]);

    // Retries would multiply every faked response; behaviour under retry is covered
    // separately in "retries a transient network fault".
    config()->set('payments.http.retries', 1);

    $this->gateway = new PaystackGateway(app(HttpFactory::class), config('payments.gateways.paystack'));

    $this->request = new TransferRequest(
        Money::naira(300),
        new RecipientAccount('0123456789', '058', 'Ada Lovelace'),
        'cashback:user-badge:1',
        'Badge cashback reward',
    );
});

function paystackRecipientCreated(string $code = 'RCP_abc'): array
{
    return ['status' => true, 'data' => ['recipient_code' => $code]];
}

function paystackTransfer(string $status, string $code = 'TRF_xyz'): array
{
    return ['status' => true, 'data' => ['transfer_code' => $code, 'status' => $status]];
}

it('registers the recipient then sends the transfer', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response(paystackTransfer('success')),
    ]);

    $result = $this->gateway->transfer($this->request);

    expect($result->successful())->toBeTrue()
        ->and($result->reference)->toBe('TRF_xyz');

    Http::assertSentCount(2);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transferrecipient')
        && $r['type'] === 'nuban'
        && $r['account_number'] === '0123456789'
        && $r['bank_code'] === '058'
        && $r['name'] === 'Ada Lovelace');
});

it('sends the amount in minor units and passes the reference through for idempotency', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response(paystackTransfer('success')),
    ]);

    $this->gateway->transfer($this->request);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transfer')
        && $r['amount'] === 30_000
        && $r['currency'] === 'NGN'
        && $r['recipient'] === 'RCP_abc'
        && $r['reference'] === 'cashback:user-badge:1'
        && $r['reason'] === 'Badge cashback reward');
});

it('authenticates with the configured secret key', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response(paystackTransfer('success')),
    ]);

    $this->gateway->transfer($this->request);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk_test_secret'));
});

it('reports an unsettled transfer as pending rather than paid', function (string $status) {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response(paystackTransfer($status)),
    ]);

    $result = $this->gateway->transfer($this->request);

    expect($result->pendingSettlement())->toBeTrue()
        ->and($result->successful())->toBeFalse();
})->with(['pending', 'otp', 'processing', 'received']);

it('treats an unknown or terminal failure status as a failure', function (string $status) {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response(paystackTransfer($status)),
    ]);

    expect($this->gateway->transfer($this->request)->failed())->toBeTrue();
})->with(['failed', 'reversed', 'abandoned']);

it('fails when paystack will not register the recipient', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(['status' => false, 'message' => 'Invalid account number']),
    ]);

    $result = $this->gateway->transfer($this->request);

    expect($result->failed())->toBeTrue()
        ->and($result->failureReason)->toBe('Paystack would not register the recipient account.');

    // The transfer must not be attempted once the recipient is unusable.
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/transfer'));
});

it('fails when paystack rejects the transfer itself', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response(['status' => false, 'message' => 'Insufficient balance']),
    ]);

    expect($this->gateway->transfer($this->request)->failureReason)->toBe('Insufficient balance');
});

it('surfaces the provider message on an http error instead of throwing', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response(['message' => 'Your balance is not enough'], 400),
    ]);

    $result = $this->gateway->transfer($this->request);

    expect($result->failed())->toBeTrue()
        ->and($result->failureReason)->toBe('Your balance is not enough');
});

it('falls back to the status code when an http error carries no message', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response('', 503),
    ]);

    expect($this->gateway->transfer($this->request)->failureReason)
        ->toBe('paystack returned HTTP 503.');
});

it('never throws when the provider is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    $result = $this->gateway->transfer($this->request);

    expect($result->failed())->toBeTrue()
        ->and($result->failureReason)->toContain('Could not reach paystack');
});

it('retries a transient network fault before giving up', function () {
    config()->set('payments.http.retries', 3);
    $this->gateway = new PaystackGateway(app(HttpFactory::class), config('payments.gateways.paystack'));

    Http::fake([
        '*/transferrecipient' => Http::sequence()
            ->push('', 500)
            ->push(paystackRecipientCreated()),
        '*/transfer' => Http::response(paystackTransfer('success')),
    ]);

    expect($this->gateway->transfer($this->request)->successful())->toBeTrue();

    Http::assertSentCount(3);
});

it('copes with a malformed body', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(paystackRecipientCreated()),
        '*/transfer' => Http::response(['status' => true, 'data' => []]),
    ]);

    $result = $this->gateway->transfer($this->request);

    // No status field means Paystack has accepted but not settled it.
    expect($result->pendingSettlement())->toBeTrue()
        ->and($result->reference)->toBe('cashback:user-badge:1');
});

function paystackResolved(string $name = 'ADA LOVELACE'): array
{
    return ['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => $name]];
}

describe('resolving an account', function () {
    it('answers with the name the bank holds', function () {
        Http::fake(['*/bank/resolve*' => Http::response(paystackResolved())]);

        $resolution = $this->gateway->resolveAccount('0123456789', '058');

        expect($resolution->resolved)->toBeTrue()
            ->and($resolution->accountName)->toBe('ADA LOVELACE');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'account_number=0123456789')
            && str_contains($r->url(), 'bank_code=058'));
    });

    it('reports an unknown account as unresolved rather than throwing', function () {
        Http::fake(['*/bank/resolve*' => Http::response(
            ['status' => false, 'message' => 'Could not resolve account name'], 422
        )]);

        $resolution = $this->gateway->resolveAccount('0000000000', '058');

        expect($resolution->failed())->toBeTrue()
            ->and($resolution->failureReason)->toBe('Could not resolve account name');
    });

    it('resolves without a name when the provider returns none', function () {
        Http::fake(['*/bank/resolve*' => Http::response(['status' => true, 'data' => []])]);

        $resolution = $this->gateway->resolveAccount('0123456789', '058');

        expect($resolution->resolved)->toBeTrue()
            ->and($resolution->accountName)->toBeNull();
    });

    it('never throws when the provider is unreachable', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        expect($this->gateway->resolveAccount('0123456789', '058')->failureReason)
            ->toContain('Could not reach paystack');
    });
});

describe('registering a recipient', function () {
    it('exchanges the bank details for a recipient code', function () {
        Http::fake(['*/transferrecipient' => Http::response(paystackRecipientCreated('RCP_new'))]);

        $registration = $this->gateway->ensureRecipient($this->request->recipient);

        expect($registration->registered)->toBeTrue()
            ->and($registration->token)->toBe('RCP_new');
    });

    it('reports a refusal without throwing', function () {
        Http::fake(['*/transferrecipient' => Http::response(['status' => false, 'message' => 'Invalid account'])]);

        $registration = $this->gateway->ensureRecipient($this->request->recipient);

        expect($registration->failed())->toBeTrue()
            ->and($registration->failureReason)->toBe('Paystack would not register the recipient account.');
    });

    it('skips registration entirely when the request already carries a code', function () {
        Http::fake(['*/transfer' => Http::response(paystackTransfer('success'))]);

        $result = $this->gateway->transfer(new TransferRequest(
            Money::naira(300),
            $this->request->recipient->withProviderToken('RCP_known'),
            'cashback:user-badge:1',
        ));

        expect($result->successful())->toBeTrue();

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/transferrecipient'));
        Http::assertSent(fn ($r) => $r['recipient'] === 'RCP_known');
    });
});

describe('verifying a transfer', function () {
    it('reports a settled transfer as successful', function () {
        Http::fake(['*/transfer/verify/*' => Http::response(paystackTransfer('success'))]);

        $update = $this->gateway->verifyTransfer('cashback:user-badge:1');

        expect($update->successful())->toBeTrue()
            ->and($update->reference)->toBe('cashback:user-badge:1')
            ->and($update->providerReference)->toBe('TRF_xyz');
    });

    it('reports one still in flight as pending', function () {
        Http::fake(['*/transfer/verify/*' => Http::response(paystackTransfer('pending'))]);

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->status)
            ->toBe(TransferStatus::Pending);
    });

    it('reports a terminal status as failed', function () {
        Http::fake(['*/transfer/verify/*' => Http::response(paystackTransfer('reversed'))]);

        $update = $this->gateway->verifyTransfer('cashback:user-badge:1');

        expect($update->failed())->toBeTrue()
            ->and($update->failureReason)->toBe('Paystack reported transfer status [reversed].');
    });

    /*
     * The important one: a provider we cannot reach has told us nothing. Recording
     * that as a failure would write off a payout that may well have been made.
     */
    it('says pending, never failed, when the provider cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->status)
            ->toBe(TransferStatus::Pending);
    });

    it('says pending when the provider answers with an error body', function () {
        Http::fake(['*/transfer/verify/*' => Http::response(['status' => false, 'message' => 'Not found'], 404)]);

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->status)
            ->toBe(TransferStatus::Pending);
    });
});
