<?php

namespace App\Payments\ValueObjects;

use App\Payments\Enums\TransferStatus;

/**
 * The outcome of a single transfer attempt against a payment provider.
 *
 * Transfers are frequently asynchronous — a provider may accept the instruction and
 * settle it minutes later — so "not failed" is not the same as "paid". Callers must
 * branch on the status rather than on a boolean.
 */
final readonly class PaymentResult
{
    /**
     * Create a new class instance.
     */
    private function __construct(
        public TransferStatus $status,
        public ?string $reference = null,
        public ?string $failureReason = null,
    ) {}

    public static function success(string $reference): self
    {
        return new self(TransferStatus::Success, $reference);
    }

    /**
     * The provider accepted the instruction but has not settled it yet.
     */
    public static function pending(?string $reference = null): self
    {
        return new self(TransferStatus::Pending, $reference);
    }

    public static function failure(string $reason, ?string $reference = null): self
    {
        return new self(TransferStatus::Failed, $reference, $reason);
    }

    public function successful(): bool
    {
        return $this->status === TransferStatus::Success;
    }

    public function failed(): bool
    {
        return $this->status === TransferStatus::Failed;
    }

    public function pendingSettlement(): bool
    {
        return $this->status === TransferStatus::Pending;
    }
}
