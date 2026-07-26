<?php

namespace Modules\Service\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Modules\Service\Interfaces\ServiceRepositoryInterface::class,
            \Modules\Service\Repositories\ServiceRepository::class
        );

        $this->app->bind(
            \Modules\Service\Interfaces\ProductRepositoryInterface::class,
            \Modules\Service\Repositories\ProductRepository::class
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
