<?php

namespace Modules\Discount\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Discount\Cache\DiscountCacheKey;

class DiscountObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            DiscountCacheKey::single($model->id),
            DiscountCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["discount:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            DiscountCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['discounts']);
    }
}
