<?php

namespace Modules\Ad\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Ad\Cache\AdCacheKey;

class AdObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            AdCacheKey::single($model->id),
        ]);
        CacheManager::forgetByTags(["ad:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            AdCacheKey::collection(),
            AdCacheKey::statistics(),
        ]);
        CacheManager::forgetByTags(['ads']);
    }
}
