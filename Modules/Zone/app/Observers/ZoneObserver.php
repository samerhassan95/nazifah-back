<?php

namespace Modules\Zone\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Zone\Cache\ZoneCacheKey;

class ZoneObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            ZoneCacheKey::single($model->id),
            ZoneCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["zone:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            ZoneCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['zones']);
    }
}
