<?php

namespace Modules\Subscription\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Modules\Subscription\Interfaces\SubscriptionRepositoryInterface::class,
            \Modules\Subscription\Repositories\SubscriptionRepository::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
