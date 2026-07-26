<?php

namespace Modules\Service\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Service\Cache\ServiceCacheKey;

class ServiceObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            ServiceCacheKey::single($model->id),
            ServiceCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["service:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            ServiceCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['services']);
    }
}
