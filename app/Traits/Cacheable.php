<?php

namespace App\Traits;

use App\Cache\CacheManager;
use Closure;

trait Cacheable
{
    /**
     * Get single record — cache-first
     */
    public static function cached(int|string $id): ?static
    {
        $key = static::cacheKeysClass()::single($id);

        return CacheManager::remember(
            key: $key,
            ttl: static::cacheTtl(),
            callback: fn () => static::find($id),
            tags: [static::cacheTag(), static::cacheTag().':'.$id]
        );
    }

    /**
     * Get collection with optional custom query
     */
    public static function cachedList(
        string $variant = 'all',
        ?Closure $query = null,
        array $filters = []
    ): mixed {
        $keysClass = static::cacheKeysClass();
        $key = empty($filters)
            ? $keysClass::collection($variant)
            : $keysClass::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: static::cacheTtl(),
            callback: $query ?? fn () => static::all(),
            tags: [static::cacheTag()]
        );
    }

    /**
     * Forget this instance from cache
     */
    public function forgetCache(): void
    {
        $keysClass = static::cacheKeysClass();

        // Always works (file + redis)
        CacheManager::forgetKeys([
            $keysClass::single($this->id),
            $keysClass::collection(),
            $keysClass::filteredCollection([]), // clears base hash
        ]);

        // Also flush by tag if driver supports it
        CacheManager::forgetByTags([
            static::cacheTag(),
            static::cacheTag().':'.$this->id,
        ]);
    }

    /**
     * Override per model if needed
     */
    protected static function cacheTtl(): int
    {
        return CacheManager::TTL_MEDIUM;
    }

    /**
     * e.g., 'admins', 'ads', 'users'
     */
    protected static function cacheTag(): string
    {
        return strtolower(class_basename(static::class)).'s';
    }

    /**
     * Resolves to e.g. Modules\Admin\Cache\AdminCacheKey
     */
    protected static function cacheKeysClass(): string
    {
        $model = class_basename(static::class);

        return "Modules\\{$model}\\Cache\\{$model}CacheKey";
    }
}
