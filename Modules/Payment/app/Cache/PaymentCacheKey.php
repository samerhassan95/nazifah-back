<?php

namespace Modules\Payment\Cache;

use App\Cache\Keys\BaseCacheKey;

class PaymentCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'payment';
    }
}
