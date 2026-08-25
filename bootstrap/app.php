<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    // Discover listeners in every bounded context, but only inside its Listeners
    // directory — scanning whole contexts would also register Actions, whose
    // handle() signatures make them look like listeners.
    ->withEvents(discover: glob(__DIR__.'/../app/Domain/*/Listeners') ?: [])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Provider callbacks settle transfers that were accepted but not yet
            // paid, so these are registered in every environment. Outside the "web"
            // group because a provider has no CSRF token; the per-provider signature
            // check is what authenticates them.
            Route::group([], base_path('routes/webhooks.php'));

            // The rest are never part of the deployed surface. The dev harness can
            // mint users and complete purchases; the admin routes rewrite the catalog
            // and can trigger real payouts. "testing" is included so both stay under
            // test. Binding substitution is still needed here, or a route parameter
            // arrives as a raw string instead of a model.
            if (app()->environment('local', 'testing')) {
                Route::middleware(SubstituteBindings::class)
                    ->prefix('dev')
                    ->name('dev.')
                    ->group(base_path('routes/dev.php'));

                Route::middleware(SubstituteBindings::class)
                    ->prefix('admin')
                    ->name('admin.')
                    ->group(base_path('routes/admin.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The payout-account write lives in routes/web.php beside the endpoint the
        // brief specifies, but it is a JSON call from a client with no session and no
        // cookie to protect. There is nothing for a CSRF token to defend here.
        $middleware->validateCsrfTokens(except: [
            'users/*/payout-account',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
