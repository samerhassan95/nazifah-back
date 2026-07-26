<?php

namespace Modules\Client\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Client\Cache\ClientCacheKey;

class ClientObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            ClientCacheKey::single($model->id),
            ClientCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["client:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            ClientCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['clients']);
    }
}
