<?php

namespace App\Payments;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FlutterwaveGateway;
use App\Payments\Gateways\MonnifyGateway;
use App\Payments\Gateways\PaystackGateway;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;

/**
 * Resolves the configured payment provider.
 *
 * Adding a provider means adding a create*Driver method and a config entry; nothing
 * that consumes PaymentGateway has to change.
 *
 * @method PaymentGateway driver(string|null $driver = null)
 */
class PaymentManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('payments.default', 'fake');
    }

    protected function createFakeDriver(): PaymentGateway
    {
        return new FakeGateway;
    }

    protected function createPaystackDriver(): PaymentGateway
    {
        return new PaystackGateway($this->container->make(HttpFactory::class), $this->configFor('paystack'));
    }

    protected function createFlutterwaveDriver(): PaymentGateway
    {
        return new FlutterwaveGateway($this->container->make(HttpFactory::class), $this->configFor('flutterwave'));
    }

    protected function createMonnifyDriver(): PaymentGateway
    {
        return new MonnifyGateway($this->container->make(HttpFactory::class), $this->configFor('monnify'));
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(string $gateway): array
    {
        return (array) $this->config->get("payments.gateways.{$gateway}", []);
    }
}
