<?php

use App\Payments\Contracts\PaymentGateway;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FlutterwaveGateway;
use App\Payments\Gateways\MonnifyGateway;
use App\Payments\Gateways\PaystackGateway;
use App\Payments\PaymentManager;

it('resolves every supported provider by name', function (string $driver, string $expected) {
    expect(app(PaymentManager::class)->driver($driver))->toBeInstanceOf($expected);
})->with([
    ['fake', FakeGateway::class],
    ['paystack', PaystackGateway::class],
    ['flutterwave', FlutterwaveGateway::class],
    ['monnify', MonnifyGateway::class],
]);

it('names each provider consistently with its driver key', function (string $driver) {
    expect(app(PaymentManager::class)->driver($driver)->name())->toBe($driver);
})->with(['fake', 'paystack', 'flutterwave', 'monnify']);

it('uses the configured default provider', function () {
    config()->set('payments.default', 'flutterwave');

    expect(app(PaymentManager::class)->driver())->toBeInstanceOf(FlutterwaveGateway::class);
});

it('defaults to the fake provider in the test environment', function () {
    expect(app(PaymentManager::class)->getDefaultDriver())->toBe('fake');
});

it('rejects a provider that is not supported', function () {
    app(PaymentManager::class)->driver('bitcoin');
})->throws(InvalidArgumentException::class);

it('reuses a resolved provider rather than rebuilding it', function () {
    $manager = app(PaymentManager::class);

    expect($manager->driver('fake'))->toBe($manager->driver('fake'));
});

it('binds the contract to the configured provider', function () {
    config()->set('payments.default', 'paystack');
    app()->forgetInstance(PaymentGateway::class);

    expect(app(PaymentGateway::class))->toBeInstanceOf(PaystackGateway::class);
});

it('hands every consumer the same gateway instance', function () {
    expect(app(PaymentGateway::class))->toBe(app(PaymentGateway::class));
});
