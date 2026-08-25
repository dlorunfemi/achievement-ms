<?php

namespace App\Payments\Contracts;

use App\Payments\ValueObjects\AccountResolution;
use App\Payments\ValueObjects\PaymentResult;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\RecipientRegistration;
use App\Payments\ValueObjects\TransferRequest;
use App\Payments\ValueObjects\TransferUpdate;

/**
 * The boundary between this application and a local payment provider.
 *
 * Implementations must treat the request's reference as authoritative: sending the
 * same reference twice must never move money twice. Implementations must also never
 * throw for a rejected transfer — a refusal is a PaymentResult::failure(), so callers
 * can record it and retry on their own schedule.
 */
interface PaymentGateway
{
    /**
     * Identifier persisted alongside the payout, e.g. "paystack".
     */
    public function name(): string;

    /**
     * Send funds to the request's recipient, returning the outcome of this attempt.
     */
    public function transfer(TransferRequest $request): PaymentResult;

    /**
     * Ask the bank who owns an account number, before any money is sent to it.
     *
     * A provider answering "no such account" is an ordinary response, not an error:
     * implementations return an unresolved AccountResolution rather than throwing.
     */
    public function resolveAccount(string $accountNumber, string $bankCode): AccountResolution;

    /**
     * Make sure the provider will accept this account as a transfer destination,
     * returning any token it wants used in place of the raw bank details.
     *
     * Providers that pay account numbers directly return RecipientRegistration::notRequired().
     */
    public function ensureRecipient(RecipientAccount $account): RecipientRegistration;

    /**
     * Ask the provider what became of a transfer, by the reference it was sent with.
     *
     * This is the polling counterpart to a provider callback and returns the same
     * TransferUpdate, so both paths settle a payout through identical code. When the
     * provider cannot be reached or gives an answer that cannot be read, the result
     * is Pending — "we still do not know" — never Failed. Reporting an unreachable
     * provider as a failed transfer would mark money that did move as never sent.
     */
    public function verifyTransfer(string $reference): TransferUpdate;
}
