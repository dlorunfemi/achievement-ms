<?php

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
