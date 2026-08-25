<?php

use App\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment Provider Callbacks
|--------------------------------------------------------------------------
|
| A transfer the provider accepts but settles out of band leaves its cashback in
| Processing, and PayBadgeCashback deliberately never re-sends it. This is what
| finally resolves those rows.
|
| Registered in every environment — production is precisely where it matters — and
| outside the "web" middleware group, because a provider has no CSRF token and no
| session. Authentication is the per-provider signature check instead.
|
*/

Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)
    ->name('webhooks.payments');
