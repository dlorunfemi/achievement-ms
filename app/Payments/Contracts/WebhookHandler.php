<?php

namespace App\Payments\Contracts;

use App\Payments\ValueObjects\TransferUpdate;

/**
 * The inbound half of a payment provider integration.
 *
 * A transfer that a provider accepts but settles out of band is only resolved when
 * the provider calls back. Implementations authenticate that callback and translate
 * it into the provider-neutral TransferUpdate the rest of the system understands.
 *
 * Implementations must never trust an unverified payload: verify() is the only thing
 * standing between a stranger and a payout marked paid.
 */
interface WebhookHandler
{
    /**
     * Identifier matching the gateway that sent the original transfer, e.g. "paystack".
     */
    public function name(): string;

    /**
     * Whether this callback genuinely came from the provider.
     *
     * The raw body is passed rather than the decoded array because signatures are
     * computed over the exact bytes the provider sent.
     *
     * @param  array<string, list<string|null>>  $headers
     */
    public function verify(string $payload, array $headers): bool;

    /**
     * Translate a verified callback into a transfer update, or null when the event
     * is not about a transfer this application cares about.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): ?TransferUpdate;
}
