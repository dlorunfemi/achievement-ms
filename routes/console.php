<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Cashback reconciliation
|--------------------------------------------------------------------------
|
| The webhook is the primary way a pending transfer settles; this is the backstop
| for callbacks that never arrive. withoutOverlapping because a slow provider must
| not stack sweeps on top of each other.
|
*/

Schedule::command('cashbacks:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();
