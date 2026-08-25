<?php

use App\Payments\ValueObjects\Money;

it('holds an amount in minor units', function () {
    $money = Money::ofMinorUnits(30_000);

    expect($money->minorUnits)->toBe(30_000)
        ->and($money->currency)->toBe('NGN');
});

it('converts whole naira to kobo', function () {
    expect(Money::naira(300)->minorUnits)->toBe(30_000);
});

it('rounds fractional naira to the nearest kobo', function () {
    expect(Money::naira(0.015)->minorUnits)->toBe(2)
        ->and(Money::naira(12.345)->minorUnits)->toBe(1_235);
});

it('exposes major units for providers that expect them', function () {
    expect(Money::ofMinorUnits(30_000)->toMajorUnits())->toBe(300.0)
        ->and(Money::ofMinorUnits(1)->toMajorUnits())->toBe(0.01);
});

it('normalises the currency to upper case', function () {
    expect(Money::ofMinorUnits(100, 'ngn')->currency)->toBe('NGN');
});

it('allows a zero amount', function () {
    expect(Money::ofMinorUnits(0)->minorUnits)->toBe(0);
});

it('refuses a negative amount', function () {
    Money::ofMinorUnits(-1);
})->throws(InvalidArgumentException::class, 'Money cannot be negative.');

it('refuses a currency that is not a three letter code', function (string $currency) {
    Money::ofMinorUnits(100, $currency);
})->with(['NG', 'NGNN', ''])->throws(InvalidArgumentException::class);

it('compares by both amount and currency', function () {
    expect(Money::ofMinorUnits(100)->equals(Money::ofMinorUnits(100)))->toBeTrue()
        ->and(Money::ofMinorUnits(100)->equals(Money::ofMinorUnits(101)))->toBeFalse()
        ->and(Money::ofMinorUnits(100, 'NGN')->equals(Money::ofMinorUnits(100, 'USD')))->toBeFalse();
});

it('formats naira with the currency symbol', function () {
    expect(Money::naira(300)->format())->toBe('₦300.00')
        ->and(Money::ofMinorUnits(123_456_789)->format())->toBe('₦1,234,567.89');
});

it('formats other currencies with the iso code', function () {
    expect(Money::ofMinorUnits(30_000, 'USD')->format())->toBe('USD 300.00');
});

it('is immutable', function () {
    expect(new ReflectionClass(Money::class))->isReadOnly()->toBeTrue();
});
