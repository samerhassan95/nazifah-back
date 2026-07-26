<?php

namespace Modules\Piece\Cache;

use App\Cache\Keys\BaseCacheKey;

class PieceCacheKey extends BaseCacheKey
{
    protected static function model(): string
    {
        return 'piece';
    }

    public static function withRelations(int $id): string
    {
        return static::single($id, 'with_relations');
    }
}
