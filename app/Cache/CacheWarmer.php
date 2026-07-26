<?php

namespace App\Cache;

class CacheWarmer
{
    /**
     * Logic to pre-warm the cache after deploy
     * This can be called from a post-deploy script or an artisan command
     */
    public function warm(): void
    {
        // To be implemented per module requirement
    }
}
