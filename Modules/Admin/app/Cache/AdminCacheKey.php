<?php

namespace Modules\Admin\Cache;

use App\Cache\Keys\BaseCacheKey;

class AdminCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'admin';
    }
}
