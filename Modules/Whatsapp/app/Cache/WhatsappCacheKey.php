<?php

namespace Modules\Whatsapp\Cache;

use App\Cache\Keys\BaseCacheKey;

class WhatsappCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'whatsapp';
    }
}
