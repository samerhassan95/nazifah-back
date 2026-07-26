<?php

namespace Modules\Chat\Cache;

use App\Cache\Keys\BaseCacheKey;

class ChatCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'chat';
    }
}
