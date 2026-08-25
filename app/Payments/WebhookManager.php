<?php

namespace App\Payments;

use App\Payments\Contracts\WebhookHandler;
use App\Payments\Webhooks\FakeWebhookHandler;
use App\Payments\Webhooks\FlutterwaveWebhookHandler;
use App\Payments\Webhooks\MonnifyWebhookHandler;
use App\Payments\Webhooks\PaystackWebhookHandler;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves the inbound half of a provider integration, mirroring PaymentManager.
 *
 * Kept separate from PaymentManager because the two are addressed differently: the
 * outbound gateway is whichever provider is configured, while a webhook names its
 * provider in the URL and must resolve even when it is not the configured default —
 * a callback for yesterday's provider still has to settle yesterday's transfer.
 *
 * @method WebhookHandler driver(string|null $driver = null)
 */
class WebhookManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('payments.default', 'fake');
    }

    /**
     * Whether a provider name maps to a handler, without booting one.
     */
    public function handles(string $provider): bool
    {
        return method_exists($this, 'create'.ucfirst($provider).'Driver');
    }

    /**
     * Resolve a handler by provider name, or null when the name is not one of ours.
     */
    public function for(string $provider): ?WebhookHandler
    {
        if (! $this->handles($provider)) {
            return null;
        }

        try {
            return $this->driver($provider);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    protected function createFakeDriver(): WebhookHandler
    {
        return new FakeWebhookHandler($this->configFor('fake'));
    }

    protected function createPaystackDriver(): WebhookHandler
    {
        return new PaystackWebhookHandler($this->configFor('paystack'));
    }

    protected function createFlutterwaveDriver(): WebhookHandler
    {
        return new FlutterwaveWebhookHandler($this->configFor('flutterwave'));
    }

    protected function createMonnifyDriver(): WebhookHandler
    {
        return new MonnifyWebhookHandler($this->configFor('monnify'));
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(string $gateway): array
    {
        return (array) $this->config->get("payments.gateways.{$gateway}", []);
    }
}
