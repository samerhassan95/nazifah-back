<?php

namespace Modules\Vendor\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Vendor\Cache\VendorCacheKey;

class VendorObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            VendorCacheKey::single($model->id),
            VendorCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["vendor:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            VendorCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['vendors']);
    }
}
