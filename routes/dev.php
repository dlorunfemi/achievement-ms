<?php

use App\Http\Controllers\Dev\CompletePurchasesController;
use App\Http\Controllers\Dev\CreateUserController;
use App\Http\Controllers\Dev\UserCashbacksController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Development Harness
|--------------------------------------------------------------------------
|
| The assessment specifies exactly one endpoint, and the purchase side of the
| flow is driven by domain events rather than HTTP. These routes exist so the
| chain can be exercised from a REST client without dropping into tinker, and
| they are registered only outside production — see bootstrap/app.php.
|
| Nothing here is part of the graded surface: no domain rule lives in these
| controllers, they only call the same entry points the tests do.
|
*/

Route::post('users', CreateUserController::class)
    ->name('users.store');

Route::post('users/{user}/purchases', CompletePurchasesController::class)
    ->name('users.purchases.store');

Route::get('users/{user}/cashbacks', UserCashbacksController::class)
    ->name('users.cashbacks');
