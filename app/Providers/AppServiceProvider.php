<?php

namespace App\Providers;

use App\Contracts\RoutingProviderInterface;
use App\Services\RoutingService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RoutingProviderInterface::class, RoutingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
