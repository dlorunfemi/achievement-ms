<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    |
    | The provider used for outbound transfers. "fake" keeps money in-process and is
    | the default for local development and the test suite.
    |
    | Supported: "fake", "paystack", "flutterwave", "monnify"
    |
    */

    'default' => env('PAYMENTS_GATEWAY', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Gateway Credentials
    |--------------------------------------------------------------------------
    |
    | Bank codes are provider-specific, so the codes stored on payout accounts must
    | match whichever provider is configured here.
    |
    */

    'gateways' => [

        'fake' => [
            'webhook_secret' => env('FAKE_WEBHOOK_SECRET', 'fake-webhook-secret'),
        ],

        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

            // Paystack signs callbacks with the same secret key; overridable only
            // because rotating the two independently is occasionally useful.
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        ],

        'flutterwave' => [
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),

            // Flutterwave does not sign the body — it echoes this string back in the
            // verif-hash header. It is a shared password, so it must not be the API
            // key and there is deliberately no fallback: unset means reject.
            'webhook_hash' => env('FLUTTERWAVE_WEBHOOK_HASH'),
        ],

        'monnify' => [
            'api_key' => env('MONNIFY_API_KEY'),
            'secret_key' => env('MONNIFY_SECRET_KEY'),
            'base_url' => env('MONNIFY_BASE_URL', 'https://api.monnify.com'),
            'source_account_number' => env('MONNIFY_SOURCE_ACCOUNT_NUMBER'),

            // Monnify signs callbacks with the client secret.
            'webhook_secret' => env('MONNIFY_WEBHOOK_SECRET'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Behaviour
    |--------------------------------------------------------------------------
    |
    | Applied to every outbound provider call. Retries cover transient network
    | faults; the request reference keeps those retries safe.
    |
    */

    'http' => [
        'timeout' => env('PAYMENTS_HTTP_TIMEOUT', 15),
        'connect_timeout' => env('PAYMENTS_HTTP_CONNECT_TIMEOUT', 5),
        'retries' => env('PAYMENTS_HTTP_RETRIES', 2),
        'retry_delay' => env('PAYMENTS_HTTP_RETRY_DELAY', 200),
    ],

];
