<?php

namespace App\Payments\ValueObjects;

use InvalidArgumentException;

/**
 * The bank account a transfer is destined for, in the shape every supported provider
 * needs: an account number, the bank's code, and the account holder's name.
 *
 * Bank codes are provider-specific (a NIP code for Paystack and Monnify, a bank code
 * for Flutterwave), so the value stored on the payout account is passed through
 * unchanged rather than translated here.
 *
 * $providerToken carries whatever the configured provider issued for this account —
 * a Paystack recipient code, for instance. It is null both when the provider needs
 * nothing registered and when nothing has been registered yet, and a gateway that
 * requires one falls back to registering it inline.
 */
final readonly class RecipientAccount
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $accountNumber,
        public string $bankCode,
        public string $accountName,
        public string $currency = 'NGN',
        public ?string $providerToken = null,
    ) {
        if ($accountNumber === '' || $bankCode === '') {
            throw new InvalidArgumentException('A recipient needs both an account number and a bank code.');
        }
    }

    /**
     * The same account, plus the token the configured provider issued for it.
     */
    public function withProviderToken(?string $token): self
    {
        return new self($this->accountNumber, $this->bankCode, $this->accountName, $this->currency, $token);
    }
}
