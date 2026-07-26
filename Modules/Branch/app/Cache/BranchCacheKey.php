<?php

namespace Modules\Branch\Cache;

use App\Cache\Keys\BaseCacheKey;

class BranchCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'branch';
    }

    public static function statistics(): string
    {
        return 'branch:v1:statistics';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
