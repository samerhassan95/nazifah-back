<?php

namespace Modules\Order\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Modules\Order\Interfaces\OrderRepositoryInterface::class,
            \Modules\Order\Repositories\OrderRepository::class
        );

        $this->app->bind(
            \Modules\Order\Interfaces\OrderItemRepositoryInterface::class,
            \Modules\Order\Repositories\OrderItemRepository::class
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
