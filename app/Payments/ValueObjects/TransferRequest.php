<?php

namespace App\Payments\ValueObjects;

use InvalidArgumentException;

/**
 * A single outbound transfer, described in provider-neutral terms.
 *
 * $reference doubles as the idempotency key. Every gateway sends it to the provider
 * so a retried attempt is recognised and settled once, rather than paying twice.
 */
final readonly class TransferRequest
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public Money $amount,
        public RecipientAccount $recipient,
        public string $reference,
        public string $narration = 'Payout',
    ) {
        if ($reference === '') {
            throw new InvalidArgumentException('A transfer needs a reference to be idempotent.');
        }

        if ($amount->minorUnits === 0) {
            throw new InvalidArgumentException('Cannot transfer a zero amount.');
        }
    }
}
