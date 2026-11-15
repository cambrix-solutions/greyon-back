<?php

namespace App\Providers;

use App\Observers\ActivityObserver;
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
        // Activity log: who changed what (see App\Observers\ActivityObserver).
        foreach (array_keys(ActivityObserver::SUBJECTS) as $model) {
            $model::observe(ActivityObserver::class);
        }
    }
}
