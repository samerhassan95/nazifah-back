<?php

namespace Modules\Admin\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Cache\AdminCacheKey;

class AdminObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            AdminCacheKey::single($model->id),
        ]);
        CacheManager::forgetByTags(["admin:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([AdminCacheKey::collection()]);
        CacheManager::forgetByTags(['admins']);
    }
}
