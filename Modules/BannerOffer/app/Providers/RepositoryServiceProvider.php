<?php

namespace Modules\BannerOffer\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Modules\BannerOffer\Interfaces\BannerOfferRepositoryInterface::class,
            \Modules\BannerOffer\Repositories\BannerOfferRepository::class
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
