<?php

use App\Payments\Enums\TransferStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\ValueObjects\Money;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\TransferRequest;

function transferOf(string $reference = 'ref-1', int $naira = 300): TransferRequest
{
    return new TransferRequest(
        Money::naira($naira),
        new RecipientAccount('0123456789', '058', 'Ada Lovelace'),
        $reference,
    );
}

it('identifies itself as the fake gateway', function () {
    expect((new FakeGateway)->name())->toBe('fake');
});

it('settles a transfer by default', function () {
    $result = (new FakeGateway)->transfer(transferOf());

    expect($result->successful())->toBeTrue()
        ->and($result->reference)->toStartWith('fake_');
});

it('records every transfer it is asked to make', function () {
    $gateway = new FakeGateway;

    $gateway->transfer(transferOf('ref-1'));
    $gateway->transfer(transferOf('ref-2'));

    expect($gateway->transferCount())->toBe(2)
        ->and($gateway->lastTransfer()->reference)->toBe('ref-2')
        ->and($gateway->transfers[0]->amount->minorUnits)->toBe(30_000);
});

it('replays a settled transfer instead of sending it twice', function () {
    $gateway = new FakeGateway;

    $first = $gateway->transfer(transferOf('ref-1'));
    $second = $gateway->transfer(transferOf('ref-1'));

    expect($gateway->transferCount())->toBe(1)
        ->and($second->reference)->toBe($first->reference);
});

it('treats a different reference as a different transfer', function () {
    $gateway = new FakeGateway;

    $gateway->transfer(transferOf('ref-1'));
    $gateway->transfer(transferOf('ref-2'));

    expect($gateway->transferCount())->toBe(2);
});

it('can be told to fail every transfer', function () {
    $result = (new FakeGateway)->alwaysFail('Insufficient balance')->transfer(transferOf());

    expect($result->failed())->toBeTrue()
        ->and($result->failureReason)->toBe('Insufficient balance');
});

it('keeps a failed transfer retryable rather than replaying it', function () {
    $gateway = (new FakeGateway)->alwaysFail();

    $gateway->transfer(transferOf('ref-1'));
    $gateway->transfer(transferOf('ref-1'));

    expect($gateway->transferCount())->toBe(2);
});

it('can be told to accept transfers without settling them', function () {
    $result = (new FakeGateway)->alwaysPend()->transfer(transferOf());

    expect($result->pendingSettlement())->toBeTrue()
        ->and($result->reference)->toStartWith('fake_');
});

it('does not replay a pending transfer, since it never settled', function () {
    $gateway = (new FakeGateway)->alwaysPend();

    $gateway->transfer(transferOf('ref-1'));
    $gateway->transfer(transferOf('ref-1'));

    expect($gateway->transferCount())->toBe(2);
});

it('can be switched back to succeeding', function () {
    $gateway = (new FakeGateway)->alwaysFail();
    expect($gateway->transfer(transferOf('ref-1'))->failed())->toBeTrue();

    $gateway->alwaysSucceed();
    expect($gateway->transfer(transferOf('ref-2'))->successful())->toBeTrue();
});

it('has no last transfer before anything is sent', function () {
    expect((new FakeGateway)->lastTransfer())->toBeNull();
});

describe('resolving accounts', function () {
    it('resolves any account, but invents no name for it', function () {
        $resolution = (new FakeGateway)->resolveAccount('0123456789', '058');

        expect($resolution->resolved)->toBeTrue()
            ->and($resolution->accountName)->toBeNull();
    });

    it('answers with a name when one is configured', function () {
        expect((new FakeGateway)->resolvesAs('ADA LOVELACE')->resolveAccount('0123456789', '058'))
            ->accountName->toBe('ADA LOVELACE');
    });

    it('can be made to refuse, the way a wrong account number would', function () {
        expect((new FakeGateway)->failResolution('No such account')->resolveAccount('0000000000', '058'))
            ->failed()->toBeTrue()
            ->failureReason->toBe('No such account');
    });
});

describe('registering recipients', function () {
    it('mints a token and records what it was asked to register', function () {
        $gateway = new FakeGateway;

        $registration = $gateway->ensureRecipient(transferOf()->recipient);

        expect($registration->registered)->toBeTrue()
            ->and($registration->token)->toStartWith('fake_rcp_')
            ->and($gateway->recipientCount())->toBe(1);
    });

    it('can be made to refuse', function () {
        $gateway = (new FakeGateway)->failRegistration('Recipient rejected');

        expect($gateway->ensureRecipient(transferOf()->recipient))
            ->failed()->toBeTrue()
            ->failureReason->toBe('Recipient rejected');

        expect($gateway->recipientCount())->toBe(0);
    });
});

describe('verifying transfers', function () {
    it('reports a reference nobody has spoken for as still in flight', function () {
        expect((new FakeGateway)->verifyTransfer('ref-1')->status)->toBe(TransferStatus::Pending);
    });

    it('reports one the provider has since settled', function () {
        $gateway = (new FakeGateway)->settle('ref-1', 'fake_TRF');

        expect($gateway->verifyTransfer('ref-1'))
            ->successful()->toBeTrue()
            ->providerReference->toBe('fake_TRF');
    });

    it('reports one the provider has since reversed', function () {
        $gateway = (new FakeGateway)->failTransfer('ref-1', 'Reversed by bank');

        expect($gateway->verifyTransfer('ref-1'))
            ->failed()->toBeTrue()
            ->failureReason->toBe('Reversed by bank');
    });
});
