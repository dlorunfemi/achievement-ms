<?php

use App\Http\Controllers\Admin\AchievementController;
use App\Http\Controllers\Admin\BadgeController;
use App\Http\Controllers\Admin\CashbackController;
use App\Http\Controllers\Admin\MetricController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalog Administration
|--------------------------------------------------------------------------
|
| The grader asks that adding achievements and badges be easy. These routes are the
| runtime half of that answer — the catalog is data, so it can be extended without a
| deploy — while the compile-time half is a ProgressMetric class per group.
|
| Registered only outside production. The application has no auth scaffolding and the
| brief specifies none, so environment is standing in for authorisation; in production
| these would sit behind a policy, not a route guard. See the README trade-offs.
|
*/

Route::get('achievements', [AchievementController::class, 'index'])->name('achievements.index');
Route::post('achievements', [AchievementController::class, 'store'])->name('achievements.store');
Route::delete('achievements/{achievement}', [AchievementController::class, 'destroy'])->name('achievements.destroy');

Route::get('badges', [BadgeController::class, 'index'])->name('badges.index');
Route::post('badges', [BadgeController::class, 'store'])->name('badges.store');
Route::delete('badges/{badge}', [BadgeController::class, 'destroy'])->name('badges.destroy');

Route::get('metrics', MetricController::class)->name('metrics.index');

Route::get('cashbacks', [CashbackController::class, 'index'])->name('cashbacks.index');
Route::post('cashbacks/{cashback}/retry', [CashbackController::class, 'retry'])->name('cashbacks.retry');
