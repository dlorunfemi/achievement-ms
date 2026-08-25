<?php

namespace App\Payments\ValueObjects;

/**
 * The answer to a name enquiry: does this account number exist at this bank, and who
 * does it belong to?
 *
 * Providers answer "no such account" with a perfectly ordinary HTTP response, so an
 * unresolved account is a value rather than an exception — the same rule the transfer
 * path follows. $accountName is nullable because a resolution can confirm an account
 * exists without the caller learning a name for it.
 */
final readonly class AccountResolution
{
    /**
     * Create a new class instance.
     */
    private function __construct(
        public bool $resolved,
        public string $accountNumber,
        public string $bankCode,
        public ?string $accountName = null,
        public ?string $failureReason = null,
    ) {}

    public static function resolved(string $accountNumber, string $bankCode, ?string $accountName = null): self
    {
        return new self(true, $accountNumber, $bankCode, $accountName);
    }

    public static function unresolved(string $accountNumber, string $bankCode, string $reason): self
    {
        return new self(false, $accountNumber, $bankCode, null, $reason);
    }

    public function failed(): bool
    {
        return ! $this->resolved;
    }
}
