<?php

namespace Modules\BannerOffer\Cache;

use App\Cache\Keys\BaseCacheKey;

class BannerOfferCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'banneroffer';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
