<?php

namespace Modules\Owner\Cache;

use App\Cache\Keys\BaseCacheKey;

class OwnerCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'owner';
    }
}
