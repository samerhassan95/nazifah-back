<?php

namespace Modules\Discount\Cache;

use App\Cache\Keys\BaseCacheKey;

class DiscountCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'discount';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
