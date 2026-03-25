<?php

namespace App\Providers;

use App\Models\Padre;
use App\Observers\PadreObserver;
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
        Padre::observe(PadreObserver::class);
    }
}
