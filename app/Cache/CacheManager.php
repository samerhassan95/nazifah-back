<?php

namespace App\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CacheManager
{
    // ─── TTL Constants ───────────────────────────────────────────────
    const TTL_REALTIME = 60;       // 1 min  → live data, counters

    const TTL_SHORT = 300;      // 5 min  → frequently updated

    const TTL_MEDIUM = 3600;     // 1 hr   → standard records

    const TTL_LONG = 86400;    // 24 hrs → slow-changing

    const TTL_STATIC = 604800;   // 7 days → almost never changes

    /**
     * Driver detection
     * File driver does NOT support Cache::tags()
     * Redis/Memcached DO support tags
     */
    public static function supportsTags(): bool
    {
        return in_array(config('cache.default'), ['redis', 'memcached']);
    }

    /**
     * Core: remember
     * Use this as the ONLY entry point for storing cache
     */
    public static function remember(
        string $key,
        int $ttl,
        Closure $callback,
        array $tags = []
    ): mixed {
        try {
            if (self::supportsTags() && ! empty($tags)) {
                return Cache::tags($tags)->remember($key, $ttl, $callback);
            }

            // Fallback for drivers that don't support tags (e.g., file, database)
            // We append a version string to the key that changes when tags are flushed.
            if (! empty($tags) && function_exists('getTagVersionKey')) {
                $versionSuffix = getTagVersionKey($tags);
                $key = "{$key}:{$versionSuffix}";
            }

            return Cache::remember($key, $ttl, $callback);
        } catch (Throwable $e) {
            Log::channel('cache')->warning('Cache remember failed — falling back to DB', [
                'key' => $key,
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);

            return $callback(); // always return data even if cache fails
        }
    }

    /**
     * Core: forget
     * Forget by key (works on all drivers)
     */
    public static function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable $e) {
            Log::channel('cache')->warning('Cache forget failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Forget by tags
     * Redis/Memcached: native flush
     * File/Database: increment version numbers via helper
     */
    public static function forgetByTags(array $tags): void
    {
        if (self::supportsTags()) {
            try {
                Cache::tags($tags)->flush();
            } catch (Throwable $e) {
                Log::channel('cache')->warning('Cache tag flush failed', [
                    'tags' => $tags,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        // Fallback for non-tag drivers: increment versions
        if (function_exists('flushCacheTags')) {
            flushCacheTags($tags);
        } else {
            Log::channel('cache')->info('forgetByTags called but flushCacheTags helper missing', [
                'tags' => $tags,
            ]);
        }
    }

    /**
     * Forget multiple explicit keys at once (works on ALL drivers including file)
     */
    public static function forgetKeys(array $keys): void
    {
        foreach ($keys as $key) {
            static::forget($key);
        }
    }
}
