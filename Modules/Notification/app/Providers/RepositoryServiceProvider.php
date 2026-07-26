<?php

namespace Modules\Notification\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Modules\Notification\Interfaces\NotificationRepositoryInterface::class,
            \Modules\Notification\Repositories\NotificationRepository::class
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
