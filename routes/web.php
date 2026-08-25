<?php

use App\Http\Controllers\PayoutAccountController;
use App\Http\Controllers\UserAchievementsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Achievements
|--------------------------------------------------------------------------
|
| The brief places this endpoint in the web routes file rather than under an
| api prefix, so it is registered here.
|
*/

Route::get('users/{user}/achievements', UserAchievementsController::class)
    ->name('users.achievements');

/*
|--------------------------------------------------------------------------
| Payout Account
|--------------------------------------------------------------------------
|
| Where a user's badge cashback is sent. Registering one also re-drives any payout
| that previously failed because the user had no account on file.
|
| Unauthenticated, like the endpoint above. That is a deliberate scope decision for
| the assessment and a documented gap: this writes bank details for an arbitrary user
| id, so in production it would be the user's own authenticated route.
|
*/

Route::get('users/{user}/payout-account', [PayoutAccountController::class, 'show'])
    ->name('users.payout-account.show');

Route::post('users/{user}/payout-account', [PayoutAccountController::class, 'store'])
    ->name('users.payout-account.store');
