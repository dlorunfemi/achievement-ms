<?php

namespace App\Payments\ValueObjects;

use App\Payments\Enums\TransferStatus;

/**
 * What a provider callback says about one transfer, in provider-neutral terms.
 *
 * The reference is the one this application generated and sent as the idempotency
 * key, not the provider's own identifier — that is carried separately, because a
 * provider is free to change its internal reference between attempts.
 */
final readonly class TransferUpdate
{
    /**
     * Create a new class instance.
     */
    private function __construct(
        public string $reference,
        public TransferStatus $status,
        public ?string $providerReference = null,
        public ?string $failureReason = null,
    ) {}

    public static function success(string $reference, ?string $providerReference = null): self
    {
        return new self($reference, TransferStatus::Success, $providerReference);
    }

    public static function failure(string $reference, ?string $reason = null, ?string $providerReference = null): self
    {
        return new self($reference, TransferStatus::Failed, $providerReference, $reason);
    }

    /**
     * The provider is still working on it. Nothing to record, but worth carrying so a
     * handler can report an in-flight event without inventing an outcome.
     */
    public static function pending(string $reference, ?string $providerReference = null): self
    {
        return new self($reference, TransferStatus::Pending, $providerReference);
    }

    public function successful(): bool
    {
        return $this->status === TransferStatus::Success;
    }

    public function failed(): bool
    {
        return $this->status === TransferStatus::Failed;
    }

    public function settled(): bool
    {
        return $this->status->isSettled();
    }
}
