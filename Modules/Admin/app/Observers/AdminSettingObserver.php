<?php

namespace Modules\Admin\Observers;

use App\Cache\CacheManager;
use App\Observers\BaseObserver;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Cache\AdminSettingCacheKey;

class AdminSettingObserver extends BaseObserver
{
    protected function clearInstanceCache(Model $model): void
    {
        CacheManager::forgetKeys([
            AdminSettingCacheKey::single($model->id),
            AdminSettingCacheKey::byKey($model->key),
        ]);
        CacheManager::forgetByTags(["admin_setting:{$model->id}"]);
    }

    protected function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            AdminSettingCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['admin_settings']);
    }
}
