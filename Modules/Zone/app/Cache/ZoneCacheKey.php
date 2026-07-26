<?php

namespace Modules\Zone\Cache;

use App\Cache\Keys\BaseCacheKey;

class ZoneCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'zone';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
