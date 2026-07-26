<?php

namespace Modules\Vendor\Cache;

use App\Cache\Keys\BaseCacheKey;

class VendorCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'vendor';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
