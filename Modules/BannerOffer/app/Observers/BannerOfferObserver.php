<?php

namespace Modules\BannerOffer\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\BannerOffer\Cache\BannerOfferCacheKey;

class BannerOfferObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            BannerOfferCacheKey::single($model->id),
            BannerOfferCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["banneroffer:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            BannerOfferCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['banneroffers']);
    }
}
