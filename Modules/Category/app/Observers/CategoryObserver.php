<?php

namespace Modules\Category\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Category\Cache\CategoryCacheKey;

class CategoryObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            CategoryCacheKey::single($model->id),
            CategoryCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["category:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            CategoryCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['categories']);
    }
}
