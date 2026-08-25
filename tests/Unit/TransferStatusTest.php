<?php

use App\Payments\Enums\TransferStatus;

it('treats success and failure as settled', function () {
    expect(TransferStatus::Success->isSettled())->toBeTrue()
        ->and(TransferStatus::Failed->isSettled())->toBeTrue();
});

it('treats a pending transfer as still in flight', function () {
    expect(TransferStatus::Pending->isSettled())->toBeFalse();
});

it('is backed by stable string values', function () {
    expect(array_column(TransferStatus::cases(), 'value'))
        ->toBe(['pending', 'success', 'failed']);
});
