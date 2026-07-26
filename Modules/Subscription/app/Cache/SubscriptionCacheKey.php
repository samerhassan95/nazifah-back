<?php

namespace Modules\Subscription\Cache;

use App\Cache\Keys\BaseCacheKey;

class SubscriptionCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'subscription';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }

    public static function vendorActive(int $vendorId): string
    {
        return "vendor:{$vendorId}:active_subscription";
    }
}
