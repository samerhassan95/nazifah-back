<?php

namespace Modules\Client\Cache;

use App\Cache\Keys\BaseCacheKey;

class ClientCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'client';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
