<?php

use App\Payments\Enums\TransferStatus;
use App\Payments\ValueObjects\PaymentResult;

it('reports a settled transfer as successful', function () {
    $result = PaymentResult::success('TRF_123');

    expect($result->status)->toBe(TransferStatus::Success)
        ->and($result->successful())->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->pendingSettlement())->toBeFalse()
        ->and($result->reference)->toBe('TRF_123')
        ->and($result->failureReason)->toBeNull();
});

it('reports an accepted but unsettled transfer as pending', function () {
    $result = PaymentResult::pending('TRF_123');

    expect($result->status)->toBe(TransferStatus::Pending)
        ->and($result->pendingSettlement())->toBeTrue()
        ->and($result->successful())->toBeFalse()
        ->and($result->failed())->toBeFalse();
});

it('carries the reason a transfer failed', function () {
    $result = PaymentResult::failure('Insufficient balance');

    expect($result->status)->toBe(TransferStatus::Failed)
        ->and($result->failed())->toBeTrue()
        ->and($result->successful())->toBeFalse()
        ->and($result->failureReason)->toBe('Insufficient balance');
});

it('keeps the provider reference on a failure when there is one', function () {
    expect(PaymentResult::failure('Reversed', 'TRF_9')->reference)->toBe('TRF_9');
});

it('allows a pending result with no reference yet', function () {
    expect(PaymentResult::pending()->reference)->toBeNull();
});
