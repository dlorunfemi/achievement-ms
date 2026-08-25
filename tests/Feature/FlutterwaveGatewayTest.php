<?php

use App\Payments\Enums\TransferStatus;
use App\Payments\Gateways\FlutterwaveGateway;
use App\Payments\ValueObjects\Money;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\TransferRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('payments.gateways.flutterwave', [
        'secret_key' => 'FLWSECK_TEST-secret',
        'base_url' => 'https://api.flutterwave.com/v3',
    ]);
    config()->set('payments.http.retries', 1);

    $this->gateway = new FlutterwaveGateway(app(HttpFactory::class), config('payments.gateways.flutterwave'));

    $this->request = new TransferRequest(
        Money::naira(300),
        new RecipientAccount('0123456789', '044', 'Ada Lovelace'),
        'cashback:user-badge:1',
        'Badge cashback reward',
    );
});

function flutterwaveTransfer(string $status, string $reference = 'cashback:user-badge:1'): array
{
    return [
        'status' => 'success',
        'message' => 'Transfer Queued Successfully',
        'data' => ['id' => 99, 'reference' => $reference, 'status' => $status],
    ];
}

it('sends the transfer in a single call, with the bank details inline', function () {
    Http::fake(['*/transfers' => Http::response(flutterwaveTransfer('SUCCESSFUL'))]);

    $result = $this->gateway->transfer($this->request);

    expect($result->successful())->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['account_bank'] === '044'
        && $r['account_number'] === '0123456789'
        && $r['beneficiary_name'] === 'Ada Lovelace'
        && $r['narration'] === 'Badge cashback reward');
});

it('sends the amount in major units, unlike paystack', function () {
    Http::fake(['*/transfers' => Http::response(flutterwaveTransfer('SUCCESSFUL'))]);

    $this->gateway->transfer($this->request);

    Http::assertSent(fn ($r) => $r['amount'] === 300.0
        && $r['currency'] === 'NGN'
        && $r['debit_currency'] === 'NGN');
});

it('passes the reference through so a retry is idempotent', function () {
    Http::fake(['*/transfers' => Http::response(flutterwaveTransfer('SUCCESSFUL'))]);

    $this->gateway->transfer($this->request);

    Http::assertSent(fn ($r) => $r['reference'] === 'cashback:user-badge:1');
});

it('authenticates with the configured secret key', function () {
    Http::fake(['*/transfers' => Http::response(flutterwaveTransfer('SUCCESSFUL'))]);

    $this->gateway->transfer($this->request);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer FLWSECK_TEST-secret'));
});

it('treats a settled status as paid, whatever case the provider uses', function (string $status) {
    Http::fake(['*/transfers' => Http::response(flutterwaveTransfer($status))]);

    expect($this->gateway->transfer($this->request)->successful())->toBeTrue();
})->with(['SUCCESSFUL', 'successful', 'SUCCESS', 'COMPLETED']);

it('treats a queued transfer as pending', function (string $status) {
    Http::fake(['*/transfers' => Http::response(flutterwaveTransfer($status))]);

    expect($this->gateway->transfer($this->request)->pendingSettlement())->toBeTrue();
})->with(['NEW', 'PENDING', 'PROCESSING']);

it('treats a terminal failure status as a failure', function () {
    Http::fake(['*/transfers' => Http::response([
        'status' => 'success',
        'data' => ['reference' => 'ref', 'status' => 'FAILED', 'complete_message' => 'DISBURSE FAILED'],
    ])]);

    $result = $this->gateway->transfer($this->request);

    expect($result->failed())->toBeTrue()
        ->and($result->failureReason)->toBe('DISBURSE FAILED');
});

it('fails when flutterwave rejects the request outright', function () {
    Http::fake(['*/transfers' => Http::response(['status' => 'error', 'message' => 'Invalid account'])]);

    expect($this->gateway->transfer($this->request)->failureReason)->toBe('Invalid account');
});

it('surfaces the provider message on an http error instead of throwing', function () {
    Http::fake(['*/transfers' => Http::response(['message' => 'Insufficient funds'], 400)]);

    $result = $this->gateway->transfer($this->request);

    expect($result->failed())->toBeTrue()
        ->and($result->failureReason)->toBe('Insufficient funds');
});

it('never throws when the provider is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect($this->gateway->transfer($this->request)->failureReason)
        ->toContain('Could not reach flutterwave');
});

it('falls back to the request reference when the body omits one', function () {
    Http::fake(['*/transfers' => Http::response(['status' => 'success', 'data' => ['status' => 'NEW']])]);

    expect($this->gateway->transfer($this->request)->reference)->toBe('cashback:user-badge:1');
});

describe('resolving an account', function () {
    it('answers with the name the bank holds', function () {
        Http::fake(['*/accounts/resolve' => Http::response([
            'status' => 'success',
            'data' => ['account_number' => '0123456789', 'account_name' => 'ADA LOVELACE'],
        ])]);

        $resolution = $this->gateway->resolveAccount('0123456789', '044');

        expect($resolution->resolved)->toBeTrue()
            ->and($resolution->accountName)->toBe('ADA LOVELACE');

        Http::assertSent(fn ($r) => $r['account_number'] === '0123456789' && $r['account_bank'] === '044');
    });

    it('reports an unknown account as unresolved rather than throwing', function () {
        Http::fake(['*/accounts/resolve' => Http::response(
            ['status' => 'error', 'message' => 'Sorry, we could not resolve this account'], 400
        )]);

        expect($this->gateway->resolveAccount('0000000000', '044'))
            ->failed()->toBeTrue()
            ->failureReason->toBe('Sorry, we could not resolve this account');
    });
});

it('has no recipient to register, and says so without calling the provider', function () {
    Http::fake();

    expect($this->gateway->ensureRecipient($this->request->recipient))
        ->registered->toBeTrue()
        ->token->toBeNull();

    Http::assertNothingSent();
});

describe('verifying a transfer', function () {
    it('reads the transfer out of the list a reference query returns', function () {
        Http::fake(['*/transfers?*' => Http::response([
            'status' => 'success',
            'data' => [['id' => 99, 'reference' => 'cashback:user-badge:1', 'status' => 'SUCCESSFUL']],
        ])]);

        $update = $this->gateway->verifyTransfer('cashback:user-badge:1');

        expect($update->successful())->toBeTrue()
            ->and($update->providerReference)->toBe('cashback:user-badge:1');
    });

    it('reads a single object body just as well', function () {
        Http::fake(['*/transfers?*' => Http::response(flutterwaveTransfer('SUCCESSFUL'))]);

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->successful())->toBeTrue();
    });

    it('reports one still queued as pending', function () {
        Http::fake(['*/transfers?*' => Http::response(flutterwaveTransfer('NEW'))]);

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->status)
            ->toBe(TransferStatus::Pending);
    });

    it('reports a terminal status as failed', function () {
        Http::fake(['*/transfers?*' => Http::response(flutterwaveTransfer('FAILED'))]);

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->failed())->toBeTrue();
    });

    it('says pending, never failed, when the provider cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->status)
            ->toBe(TransferStatus::Pending);
    });

    it('says pending when the provider knows nothing about the reference', function () {
        Http::fake(['*/transfers?*' => Http::response(['status' => 'success', 'data' => []])]);

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->status)
            ->toBe(TransferStatus::Pending);
    });
});
