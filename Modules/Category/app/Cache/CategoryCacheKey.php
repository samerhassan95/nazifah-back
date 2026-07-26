<?php

namespace Modules\Category\Cache;

use App\Cache\Keys\BaseCacheKey;

class CategoryCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'category';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
