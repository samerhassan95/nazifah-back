<?php

namespace App\Providers;

use App\Events\DriverAssigned;
use App\Listeners\SendDriverAssignmentNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
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
        // Explicit registration (not relying on auto-discovery): DriverAssigned
        // was dispatched from OrderStatusService::assignPickupDriver()/
        // assignDeliveryDriver() but never actually reached this listener — no
        // "new pickup/delivery assignment" push ever went to the driver, nor did
        // the reassignment-cancelled notifications this listener also sends.
        Event::listen(DriverAssigned::class, SendDriverAssignmentNotification::class);

        // WebSocket broadcast auth: allow client, vendor, or driver to authorize private channels
        Broadcast::routes(['middleware' => ['api', 'auth:sanctum']]);

        $channelsFile = base_path('routes/channels.php');
        if (file_exists($channelsFile)) {
            require $channelsFile;
        }
    }
}
