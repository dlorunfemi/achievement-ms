<?php

use App\Domain\Cashback\Enums\PayoutStatus;

it('treats a paid payout as terminal', function () {
    expect(PayoutStatus::Paid->isRetryable())->toBeFalse();
});

it('allows every unpaid state to be retried', function (PayoutStatus $status) {
    expect($status->isRetryable())->toBeTrue();
})->with([
    PayoutStatus::Pending,
    PayoutStatus::Processing,
    PayoutStatus::Failed,
]);

it('is backed by stable string values', function () {
    expect(array_column(PayoutStatus::cases(), 'value'))
        ->toBe(['pending', 'processing', 'paid', 'failed']);
});
