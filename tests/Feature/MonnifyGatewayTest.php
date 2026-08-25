<?php

use App\Payments\Enums\TransferStatus;
use App\Payments\Gateways\MonnifyGateway;
use App\Payments\ValueObjects\Money;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\TransferRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('payments.gateways.monnify', [
        'api_key' => 'MK_TEST_KEY',
        'secret_key' => 'MK_TEST_SECRET',
        'base_url' => 'https://sandbox.monnify.com',
        'source_account_number' => '3000000001',
    ]);
    config()->set('payments.http.retries', 1);

    Cache::flush();

    $this->gateway = new MonnifyGateway(app(HttpFactory::class), config('payments.gateways.monnify'));

    $this->request = new TransferRequest(
        Money::naira(300),
        new RecipientAccount('0123456789', '058', 'Ada Lovelace'),
        'cashback:user-badge:1',
        'Badge cashback reward',
    );
});

function monnifyLogin(string $token = 'token-abc', int $expiresIn = 3600): array
{
    return ['requestSuccessful' => true, 'responseBody' => ['accessToken' => $token, 'expiresIn' => $expiresIn]];
}

function monnifyDisbursement(string $status, string $reference = 'cashback:user-badge:1'): array
{
    return ['requestSuccessful' => true, 'responseBody' => ['status' => $status, 'reference' => $reference]];
}

it('authenticates then disburses', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin()),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement('SUCCESS')),
    ]);

    expect($this->gateway->transfer($this->request)->successful())->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/auth/login')
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('MK_TEST_KEY:MK_TEST_SECRET')));
});

it('sends the amount in major units with the configured source account', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin()),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement('SUCCESS')),
    ]);

    $this->gateway->transfer($this->request);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'disbursements')
        && $r['amount'] === 300.0
        && $r['destinationBankCode'] === '058'
        && $r['destinationAccountNumber'] === '0123456789'
        && $r['sourceAccountNumber'] === '3000000001'
        && $r['reference'] === 'cashback:user-badge:1'
        && $r['narration'] === 'Badge cashback reward');
});

it('uses the bearer token it just obtained', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin('token-xyz')),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement('SUCCESS')),
    ]);

    $this->gateway->transfer($this->request);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'disbursements')
        && $r->hasHeader('Authorization', 'Bearer token-xyz'));
});

it('caches the token so a burst of payouts does not re-authenticate', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin()),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement('SUCCESS')),
    ]);

    $this->gateway->transfer($this->request);
    $this->gateway->transfer(new TransferRequest(
        Money::naira(300),
        new RecipientAccount('0123456789', '058', 'Ada Lovelace'),
        'cashback:user-badge:2',
    ));

    // Two disbursements, but only one login.
    Http::assertSentCount(3);
});

it('re-authenticates once the cached token is gone', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin()),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement('SUCCESS')),
    ]);

    $this->gateway->transfer($this->request);
    Cache::flush();
    $this->gateway->transfer($this->request);

    Http::assertSentCount(4);
});

it('does not cache a token that expires sooner than the safety margin', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin('short-lived', 30)),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement('SUCCESS')),
    ]);

    $this->gateway->transfer($this->request);
    $this->gateway->transfer($this->request);

    // Logged in for each transfer, because the token was too short-lived to keep.
    Http::assertSentCount(4);
});

it('treats a settled status as paid', function (string $status) {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin()),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement($status)),
    ]);

    expect($this->gateway->transfer($this->request)->successful())->toBeTrue();
})->with(['SUCCESS', 'successful', 'COMPLETED']);

it('treats an in-flight status as pending', function (string $status) {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin()),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement($status)),
    ]);

    expect($this->gateway->transfer($this->request)->pendingSettlement())->toBeTrue();
})->with(['PENDING', 'PROCESSING', 'OTP_EMAIL_DISPATCH', 'IN_PROGRESS']);

it('fails when authentication is refused', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(['requestSuccessful' => false, 'responseMessage' => 'Bad credentials']),
    ]);

    $result = $this->gateway->transfer($this->request);

    expect($result->failed())->toBeTrue()
        ->and($result->failureReason)->toBe('Could not authenticate with Monnify.');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'disbursements'));
});

it('fails when the login body carries no token', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(['requestSuccessful' => true, 'responseBody' => []]),
    ]);

    expect($this->gateway->transfer($this->request)->failureReason)
        ->toBe('Could not authenticate with Monnify.');
});

it('fails when monnify rejects the disbursement', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin()),
        '*/api/v2/disbursements/single' => Http::response([
            'requestSuccessful' => false,
            'responseMessage' => 'Insufficient balance in source account',
        ]),
    ]);

    expect($this->gateway->transfer($this->request)->failureReason)
        ->toBe('Insufficient balance in source account');
});

it('treats an unknown disbursement status as a failure', function () {
    Http::fake([
        '*/api/v1/auth/login' => Http::response(monnifyLogin()),
        '*/api/v2/disbursements/single' => Http::response(monnifyDisbursement('REVERSED')),
    ]);

    expect($this->gateway->transfer($this->request)->failed())->toBeTrue();
});

it('never throws when the provider is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect($this->gateway->transfer($this->request)->failureReason)
        ->toContain('Could not reach monnify');
});

describe('resolving an account', function () {
    it('answers with the name the bank holds', function () {
        Http::fake([
            '*/api/v1/auth/login' => Http::response(monnifyLogin()),
            '*/disbursements/account/validate*' => Http::response([
                'requestSuccessful' => true,
                'responseBody' => ['accountNumber' => '0123456789', 'accountName' => 'ADA LOVELACE'],
            ]),
        ]);

        $resolution = $this->gateway->resolveAccount('0123456789', '058');

        expect($resolution->resolved)->toBeTrue()
            ->and($resolution->accountName)->toBe('ADA LOVELACE');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'accountNumber=0123456789')
            && str_contains($r->url(), 'bankCode=058'));
    });

    it('reports an unknown account as unresolved rather than throwing', function () {
        Http::fake([
            '*/api/v1/auth/login' => Http::response(monnifyLogin()),
            '*/disbursements/account/validate*' => Http::response([
                'requestSuccessful' => false,
                'responseMessage' => 'Invalid account details',
            ]),
        ]);

        expect($this->gateway->resolveAccount('0000000000', '058'))
            ->failed()->toBeTrue()
            ->failureReason->toBe('Invalid account details');
    });

    it('reports a failure to authenticate as an unresolved account', function () {
        Http::fake(['*/api/v1/auth/login' => Http::response(['requestSuccessful' => false])]);

        expect($this->gateway->resolveAccount('0123456789', '058')->failureReason)
            ->toBe('Could not authenticate with Monnify.');
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
    it('reports a settled disbursement as successful', function () {
        Http::fake([
            '*/api/v1/auth/login' => Http::response(monnifyLogin()),
            '*/disbursements/single/summary*' => Http::response(monnifyDisbursement('SUCCESS')),
        ]);

        $update = $this->gateway->verifyTransfer('cashback:user-badge:1');

        expect($update->successful())->toBeTrue()
            ->and($update->reference)->toBe('cashback:user-badge:1');
    });

    it('reports one still in flight as pending', function () {
        Http::fake([
            '*/api/v1/auth/login' => Http::response(monnifyLogin()),
            '*/disbursements/single/summary*' => Http::response(monnifyDisbursement('PENDING')),
        ]);

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->status)
            ->toBe(TransferStatus::Pending);
    });

    it('reports a reversal as failed', function () {
        Http::fake([
            '*/api/v1/auth/login' => Http::response(monnifyLogin()),
            '*/disbursements/single/summary*' => Http::response(monnifyDisbursement('REVERSED')),
        ]);

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->failed())->toBeTrue();
    });

    it('says pending, never failed, when the provider cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        expect($this->gateway->verifyTransfer('cashback:user-badge:1')->status)
            ->toBe(TransferStatus::Pending);
    });
});
