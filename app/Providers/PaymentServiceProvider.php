<?php

namespace App\Providers;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\PaymentManager;
use App\Payments\WebhookManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the shared Payments module. Kept separate from DomainServiceProvider because
 * payments are infrastructure available to any feature, not part of one domain.
 */
class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentManager::class, fn (Application $app): PaymentManager => new PaymentManager($app));

        // Consumers depend on the contract and get whichever provider is configured.
        $this->app->singleton(
            PaymentGateway::class,
            fn (Application $app): PaymentGateway => $app->make(PaymentManager::class)->driver(),
        );

        // Inbound callbacks name their provider in the URL, so this one is resolved
        // per request rather than bound to a single contract instance.
        $this->app->singleton(WebhookManager::class, fn (Application $app): WebhookManager => new WebhookManager($app));
    }
}
