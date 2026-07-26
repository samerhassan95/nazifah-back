<?php

namespace Modules\Notification\Cache;

use App\Cache\Keys\BaseCacheKey;

class NotificationCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'notification';
    }
}
