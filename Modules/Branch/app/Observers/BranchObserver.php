<?php

namespace Modules\Branch\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Cache\BranchCacheKey;

class BranchObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            BranchCacheKey::single($model->id),
            BranchCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["branch:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            BranchCacheKey::collection(),
            BranchCacheKey::statistics(),
        ]);
        CacheManager::forgetByTags(['branches', 'zones', 'vendors']);
    }
}
