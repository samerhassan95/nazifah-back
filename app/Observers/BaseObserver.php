<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

abstract class BaseObserver
{
    abstract protected function clearInstanceCache(Model $model): void;

    abstract protected function clearCollectionCache(): void;

    public function created(Model $model): void
    {
        $this->clearCollectionCache();          // new record → lists stale
    }

    public function updated(Model $model): void
    {
        $this->clearInstanceCache($model);      // record changed
        $this->clearCollectionCache();          // lists may be stale
    }

    public function deleted(Model $model): void
    {
        $this->clearInstanceCache($model);
        $this->clearCollectionCache();
    }

    public function restored(Model $model): void // SoftDeletes support
    {
        $this->clearInstanceCache($model);
        $this->clearCollectionCache();
    }
}
