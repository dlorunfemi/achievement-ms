<?php

namespace App\Domain\Cashback\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';

    /**
     * Whether a payout in this state may still be attempted against the gateway.
     * Paid is terminal; anything else is safe to retry because the gateway call is
     * keyed by the cashback's idempotency key.
     */
    public function isRetryable(): bool
    {
        return $this !== self::Paid;
    }

    /**
     * Whether the provider has finished with this payout, one way or the other.
     * Pending and Processing are still in flight.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Failed], true);
    }
}
