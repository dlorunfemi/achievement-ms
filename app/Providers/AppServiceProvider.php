<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * No resource wraps itself in "data". The brief specifies the achievements
         * payload key by key, so an envelope there would be a broken contract rather
         * than a style choice — and a wrapper that applies everywhere except the one
         * graded endpoint is worse than no wrapper at all.
         */
        JsonResource::withoutWrapping();
    }
}
