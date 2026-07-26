<?php

namespace Modules\Driver\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Driver\Cache\DriverCacheKey;

class DriverObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            DriverCacheKey::single($model->id),
            DriverCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["driver:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            DriverCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['drivers']);
    }
}
