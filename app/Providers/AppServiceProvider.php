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
        // Without this, a server whose php.ini leaves serialize_precision at an old
        // fixed value (not -1) can json_encode an already-rounded float like
        // round(4.06, 2) as "4.0600000000000005" — the float's exact IEEE-754 binary
        // value leaking through instead of its shortest round-trip representation.
        // -1 (PHP's own recommended default since 7.1) always encodes the shortest
        // string that reads back to the same float, so a rounded price stays clean
        // in every JSON response regardless of the host's php.ini.
        ini_set('serialize_precision', -1);
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
