<?php

namespace Modules\Zone\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Modules\Zone\Interfaces\ZoneRepositoryInterface::class,
            \Modules\Zone\Repositories\ZoneRepository::class
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
