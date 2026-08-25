<?php

namespace App\Payments\ValueObjects;

/**
 * What a provider needs on file before it will pay a bank account.
 *
 * Paystack will not transfer to a raw account number and hands back a recipient code
 * to use instead; Flutterwave and Monnify take the account details inline and need
 * nothing registered at all. Both are a successful registration — one simply carries
 * no token — so callers branch on failed(), never on the token being null.
 */
final readonly class RecipientRegistration
{
    /**
     * Create a new class instance.
     */
    private function __construct(
        public bool $registered,
        public ?string $token = null,
        public ?string $failureReason = null,
    ) {}

    public static function registered(string $token): self
    {
        return new self(true, $token);
    }

    /**
     * The provider pays account numbers directly and has nothing to register.
     */
    public static function notRequired(): self
    {
        return new self(true);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public function failed(): bool
    {
        return ! $this->registered;
    }
}
