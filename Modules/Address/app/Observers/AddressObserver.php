<?php

namespace Modules\Address\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Address\Cache\AddressCacheKey;

class AddressObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            AddressCacheKey::single($model->id),
            AddressCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["address:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            AddressCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['addresses']);
    }
}
