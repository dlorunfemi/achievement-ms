<?php

namespace App\Payments\ValueObjects;

use InvalidArgumentException;

/**
 * An immutable amount held in minor units (kobo for NGN).
 *
 * Money never exists as a float in this application. Every persisted amount is an
 * integer column plus a currency, and this object is the only thing that converts
 * between the two representations.
 */
final readonly class Money
{
    /**
     * Create a new class instance.
     */
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        if (mb_strlen($currency) !== 3) {
            throw new InvalidArgumentException("Currency must be a 3-letter ISO code, got [{$currency}].");
        }
    }

    /**
     * Build from a minor-unit figure, which is how amounts are persisted.
     */
    public static function ofMinorUnits(int $minorUnits, string $currency = 'NGN'): self
    {
        return new self($minorUnits, mb_strtoupper($currency));
    }

    /**
     * Build from a whole-currency figure, e.g. the ₦300 badge reward.
     */
    public static function naira(int|float $naira): self
    {
        return new self((int) round($naira * 100), 'NGN');
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency === $other->currency;
    }

    /**
     * The major-unit representation some gateways expect in their JSON payloads.
     */
    public function toMajorUnits(): float
    {
        return $this->minorUnits / 100;
    }

    public function format(): string
    {
        return ($this->currency === 'NGN' ? '₦' : $this->currency.' ')
            .number_format($this->toMajorUnits(), 2);
    }
}
