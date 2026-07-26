<?php

namespace Modules\Address\Cache;

use App\Cache\Keys\BaseCacheKey;

class AddressCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'address';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
