<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    // Discover listeners in every bounded context, but only inside its Listeners
    // directory — scanning whole contexts would also register Actions, whose
    // handle() signatures make them look like listeners.
    ->withEvents(discover: glob(__DIR__.'/../app/Domain/*/Listeners') ?: [])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
