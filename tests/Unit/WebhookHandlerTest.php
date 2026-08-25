<?php

use App\Payments\Enums\TransferStatus;
use App\Payments\Webhooks\FakeWebhookHandler;
use App\Payments\Webhooks\FlutterwaveWebhookHandler;
use App\Payments\Webhooks\MonnifyWebhookHandler;
use App\Payments\Webhooks\PaystackWebhookHandler;

describe('signature verification', function () {
    it('accepts a Paystack signature computed over the raw body', function () {
        $handler = new PaystackWebhookHandler(['secret_key' => 'sk_test']);
        $body = '{"event":"transfer.success"}';

        expect($handler->verify($body, [
            'x-paystack-signature' => [hash_hmac('sha512', $body, 'sk_test')],
        ]))->toBeTrue();
    });

    it('rejects a Paystack signature computed over a different body', function () {
        $handler = new PaystackWebhookHandler(['secret_key' => 'sk_test']);

        expect($handler->verify('{"event":"transfer.success"}', [
            'x-paystack-signature' => [hash_hmac('sha512', '{"tampered":true}', 'sk_test')],
        ]))->toBeFalse();
    });

    it('prefers an explicitly configured Paystack webhook secret over the API key', function () {
        $handler = new PaystackWebhookHandler(['secret_key' => 'sk_test', 'webhook_secret' => 'whsec']);
        $body = '{}';

        expect($handler->verify($body, ['x-paystack-signature' => [hash_hmac('sha512', $body, 'whsec')]]))->toBeTrue()
            ->and($handler->verify($body, ['x-paystack-signature' => [hash_hmac('sha512', $body, 'sk_test')]]))->toBeFalse();
    });

    it('matches headers case-insensitively, because proxies rewrite the casing', function () {
        $handler = new PaystackWebhookHandler(['secret_key' => 'sk_test']);
        $body = '{}';

        expect($handler->verify($body, ['X-Paystack-Signature' => [hash_hmac('sha512', $body, 'sk_test')]]))->toBeTrue();
    });

    it('compares the Flutterwave hash for equality rather than signing the body', function () {
        $handler = new FlutterwaveWebhookHandler(['webhook_hash' => 'shared-hash']);

        expect($handler->verify('{}', ['verif-hash' => ['shared-hash']]))->toBeTrue()
            ->and($handler->verify('{}', ['verif-hash' => ['wrong']]))->toBeFalse();
    });

    it('will not fall back to the Flutterwave API key as a webhook hash', function () {
        $handler = new FlutterwaveWebhookHandler(['secret_key' => 'FLWSECK_TEST']);

        expect($handler->verify('{}', ['verif-hash' => ['FLWSECK_TEST']]))->toBeFalse();
    });

    it('accepts a Monnify signature computed over the raw body', function () {
        $handler = new MonnifyWebhookHandler(['secret_key' => 'monnify-secret']);
        $body = '{"eventType":"SUCCESSFUL_DISBURSEMENT"}';

        expect($handler->verify($body, [
            'monnify-signature' => [hash_hmac('sha512', $body, 'monnify-secret')],
        ]))->toBeTrue();
    });

    it('fails closed when the provider has no secret configured', function (object $handler) {
        expect($handler->verify('{}', []))->toBeFalse()
            ->and($handler->verify('{}', ['x-paystack-signature' => ['']]))->toBeFalse();
    })->with([
        fn () => new PaystackWebhookHandler([]),
        fn () => new FlutterwaveWebhookHandler([]),
        fn () => new MonnifyWebhookHandler([]),
        fn () => new FakeWebhookHandler([]),
    ]);

    it('rejects a missing signature header even when a secret is configured', function () {
        $handler = new PaystackWebhookHandler(['secret_key' => 'sk_test']);

        expect($handler->verify('{}', []))->toBeFalse();
    });
});

describe('payload translation', function () {
    it('reads a Paystack success', function () {
        $update = (new PaystackWebhookHandler([]))->parse([
            'event' => 'transfer.success',
            'data' => ['reference' => 'cashback:user-badge:7', 'transfer_code' => 'TRF_abc'],
        ]);

        expect($update->status)->toBe(TransferStatus::Success)
            ->and($update->reference)->toBe('cashback:user-badge:7')
            ->and($update->providerReference)->toBe('TRF_abc');
    });

    it('treats a Paystack reversal as a failure, carrying the reason', function () {
        $update = (new PaystackWebhookHandler([]))->parse([
            'event' => 'transfer.reversed',
            'data' => ['reference' => 'ref-1', 'reason' => 'Account does not exist'],
        ]);

        expect($update->status)->toBe(TransferStatus::Failed)
            ->and($update->failureReason)->toBe('Account does not exist');
    });

    it('ignores Paystack events that are not about transfers', function () {
        expect((new PaystackWebhookHandler([]))->parse([
            'event' => 'charge.success',
            'data' => ['reference' => 'ref-1'],
        ]))->toBeNull();
    });

    it('ignores a payload with no reference to match on', function (object $handler, array $payload) {
        expect($handler->parse($payload))->toBeNull();
    })->with([
        [fn () => new PaystackWebhookHandler([]), ['event' => 'transfer.success', 'data' => []]],
        [fn () => new FlutterwaveWebhookHandler([]), ['event' => 'transfer.completed', 'data' => []]],
        [fn () => new MonnifyWebhookHandler([]), ['eventType' => 'SUCCESSFUL_DISBURSEMENT', 'eventData' => []]],
        [fn () => new FakeWebhookHandler([]), ['status' => 'success']],
    ]);

    it('reads a Flutterwave completion', function () {
        $update = (new FlutterwaveWebhookHandler([]))->parse([
            'event' => 'transfer.completed',
            'data' => ['reference' => 'ref-2', 'status' => 'SUCCESSFUL', 'id' => 9911],
        ]);

        expect($update->status)->toBe(TransferStatus::Success)
            ->and($update->providerReference)->toBe('9911');
    });

    it('keeps an in-flight Flutterwave transfer pending rather than guessing', function () {
        $update = (new FlutterwaveWebhookHandler([]))->parse([
            'event' => 'transfer.completed',
            'data' => ['reference' => 'ref-2', 'status' => 'PENDING'],
        ]);

        expect($update->status)->toBe(TransferStatus::Pending)
            ->and($update->settled())->toBeFalse();
    });

    it('reads a Monnify disbursement from its eventData envelope', function () {
        $update = (new MonnifyWebhookHandler([]))->parse([
            'eventType' => 'FAILED_DISBURSEMENT',
            'eventData' => ['reference' => 'ref-3', 'transactionReference' => 'MFDS|123', 'narration' => 'Rejected'],
        ]);

        expect($update->status)->toBe(TransferStatus::Failed)
            ->and($update->providerReference)->toBe('MFDS|123')
            ->and($update->failureReason)->toBe('Rejected');
    });

    it('ignores a Monnify collection event', function () {
        expect((new MonnifyWebhookHandler([]))->parse([
            'eventType' => 'SUCCESSFUL_TRANSACTION',
            'eventData' => ['reference' => 'ref-3'],
        ]))->toBeNull();
    });
});
