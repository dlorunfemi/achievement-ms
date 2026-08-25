<?php

use App\Payments\ValueObjects\Money;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\TransferRequest;

function aRecipient(): RecipientAccount
{
    return new RecipientAccount('0123456789', '058', 'Ada Lovelace');
}

it('describes a transfer in provider neutral terms', function () {
    $request = new TransferRequest(Money::naira(300), aRecipient(), 'cashback:user-badge:1', 'Badge reward');

    expect($request->amount->minorUnits)->toBe(30_000)
        ->and($request->recipient->bankCode)->toBe('058')
        ->and($request->reference)->toBe('cashback:user-badge:1')
        ->and($request->narration)->toBe('Badge reward');
});

it('defaults the narration', function () {
    expect((new TransferRequest(Money::naira(300), aRecipient(), 'ref'))->narration)->toBe('Payout');
});

it('refuses a transfer with no reference, because it could not be made idempotent', function () {
    new TransferRequest(Money::naira(300), aRecipient(), '');
})->throws(InvalidArgumentException::class, 'A transfer needs a reference to be idempotent.');

it('refuses a zero amount', function () {
    new TransferRequest(Money::ofMinorUnits(0), aRecipient(), 'ref');
})->throws(InvalidArgumentException::class, 'Cannot transfer a zero amount.');

it('refuses a recipient with no account number or bank code', function (string $accountNumber, string $bankCode) {
    new RecipientAccount($accountNumber, $bankCode, 'Ada Lovelace');
})->with([['', '058'], ['0123456789', '']])->throws(InvalidArgumentException::class);
