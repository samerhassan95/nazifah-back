<?php

namespace Modules\Order\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Order\Cache\OrderCacheKey;

class OrderObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            OrderCacheKey::single($model->id),
            OrderCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["order:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        // For orders, we flush the whole collection tag because they are highly dynamic
        // and vendor-specific lists are many.
        CacheManager::forgetKeys([
            OrderCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['orders']);
    }
}
