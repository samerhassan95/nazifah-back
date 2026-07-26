<?php

namespace Modules\Order\Cache;

use App\Cache\Keys\BaseCacheKey;

class OrderCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'order';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }

    public static function vendorList(int $vendorId, array $filters): string
    {
        $hash = md5(serialize($filters));

        return "order:v1:vendor:{$vendorId}:list:{$hash}";
    }
}
