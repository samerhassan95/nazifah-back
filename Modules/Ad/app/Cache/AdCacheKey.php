<?php

namespace Modules\Ad\Cache;

use App\Cache\Keys\BaseCacheKey;

class AdCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'ad';
    }

    public static function statistics(): string
    {
        return 'ad:v1:statistics';
    }
}
