<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
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
        // WebSocket broadcast auth: allow client, vendor, or driver to authorize private channels
        Broadcast::routes(['middleware' => ['api', 'auth:sanctum']]);

        $channelsFile = base_path('routes/channels.php');
        if (file_exists($channelsFile)) {
            require $channelsFile;
        }
    }
}
