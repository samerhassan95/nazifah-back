<?php

namespace Modules\Piece\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Piece\Cache\PieceCacheKey;

class PieceObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            PieceCacheKey::single($model->id),
            PieceCacheKey::withRelations($model->id),
        ]);
        CacheManager::forgetByTags(["piece:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            PieceCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['pieces']);
    }
}
