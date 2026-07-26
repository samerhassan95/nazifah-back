<?php

namespace Modules\Admin\Cache;

use App\Cache\Keys\BaseCacheKey;

class AdminSettingCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'admin_setting';
    }

    public static function byKey(string $key): string
    {
        return "admin_setting:key:{$key}";
    }
}
