<?php

namespace Modules\Piece\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Modules\Piece\Interfaces\PieceRepositoryInterface::class,
            \Modules\Piece\Repositories\PieceRepository::class
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
