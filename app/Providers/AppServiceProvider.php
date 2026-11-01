<?php

namespace App\Providers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Broadcast;
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
        // Without this, a whole-number float like 3.0 (e.g. a Google Maps distance
        // of exactly 3km, 3000m / 1000) serializes to JSON as "3", not "3.0" — a
        // bare int on the wire. Strict client-side models (e.g. Dart/Flutter casting
        // a decoded JSON number straight to `double`) can then throw a type error,
        // even though the PHP value was never anything but a float server-side.
        // JSON_PRESERVE_ZERO_FRACTION keeps every float's decimal point in every
        // response()->json(...) call app-wide.
        ResponseFactory::macro('json', function ($data = [], $status = 200, array $headers = [], $options = 0) {
            return new JsonResponse($data, $status, $headers, $options | JSON_PRESERVE_ZERO_FRACTION);
        });

        // WebSocket broadcast auth: allow client, vendor, or driver to authorize private channels
        Broadcast::routes(['middleware' => ['api', 'auth:sanctum']]);

        $channelsFile = base_path('routes/channels.php');
        if (file_exists($channelsFile)) {
            require $channelsFile;
        }
    }
}
