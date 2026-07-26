<?php

namespace Modules\Driver\Cache;

use App\Cache\Keys\BaseCacheKey;

class DriverCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'driver';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
