<?php

namespace Modules\Service\Cache;

use App\Cache\Keys\BaseCacheKey;

class ServiceCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'service';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
