<?php

namespace App\Cache\Keys;

abstract class BaseCacheKey
{
    /**
     * Each subclass defines its model name
     * e.g., 'admin', 'ad', 'user'
     */
    abstract protected static function model(): string;

    /**
     * Single record key: admin:v1:123
     */
    public static function single(int|string $id, string $variant = ''): string
    {
        $base = static::model().':v1:'.$id;

        return $variant ? "{$base}:{$variant}" : $base;
    }

    /**
     * Collection key: admin:v1:collection:all
     */
    public static function collection(string $variant = 'all'): string
    {
        return static::model().':v1:collection:'.$variant;
    }

    /**
     * Key with filters hash (for paginated/filtered lists)
     * admin:v1:collection:list:a1b2c3d4
     */
    public static function filteredCollection(array $filters): string
    {
        $hash = md5(serialize($filters));

        return static::model().':v1:collection:list:'.$hash;
    }
}
