<?php

namespace Modules\Admin\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Admin Module Repositories
        $this->app->bind(
            \Modules\Admin\Interfaces\AdminRepositoryInterface::class,
            \Modules\Admin\Repositories\AdminRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\ClientRepositoryInterface::class,
            \Modules\Admin\Repositories\ClientRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\OwnerRepositoryInterface::class,
            \Modules\Admin\Repositories\OwnerRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\DriverRepositoryInterface::class,
            \Modules\Admin\Repositories\DriverRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\VendorRepositoryInterface::class,
            \Modules\Admin\Repositories\VendorRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\OrderRepositoryInterface::class,
            \Modules\Admin\Repositories\OrderRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\CategoryRepositoryInterface::class,
            \Modules\Admin\Repositories\CategoryRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\ServiceRepositoryInterface::class,
            \Modules\Admin\Repositories\ServiceRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\ZoneRepositoryInterface::class,
            \Modules\Admin\Repositories\ZoneRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\BranchRepositoryInterface::class,
            \Modules\Admin\Repositories\BranchRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\AdRepositoryInterface::class,
            \Modules\Admin\Repositories\AdRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\BannerOfferRepositoryInterface::class,
            \Modules\Admin\Repositories\BannerOfferRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\DiscountRepositoryInterface::class,
            \Modules\Admin\Repositories\DiscountRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\NotificationRepositoryInterface::class,
            \Modules\Admin\Repositories\NotificationRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\AddressRepositoryInterface::class,
            \Modules\Admin\Repositories\AddressRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\PieceRepositoryInterface::class,
            \Modules\Admin\Repositories\PieceRepository::class
        );

        $this->app->bind(
            \Modules\Admin\Interfaces\IconRepositoryInterface::class,
            \Modules\Admin\Repositories\IconRepository::class
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
