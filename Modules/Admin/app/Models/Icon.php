<?php

namespace Modules\Admin\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Enums\IconType;

class Icon extends Model
{
    use Cacheable;
    use HasSoftDeletes;

    protected $fillable = [
        'path',
        'type',
    ];

    protected $casts = [
        'type' => IconType::class,
    ];

    /**
     * Get the full URL for the icon path.
     */
    public function getFullPathAttribute(): string
    {
        if (! $this->path) {
            return '';
        }

        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        return config('app.url').$this->path;
    }

    /**
     * Get services using this icon
     */
    public function services(): HasMany
    {
        return $this->hasMany(\Modules\Service\Models\Service::class);
    }

    /**
     * Get pieces using this icon
     */
    public function pieces(): HasMany
    {
        return $this->hasMany(\Modules\Piece\Models\Piece::class);
    }

    /**
     * Get service additions using this icon
     */
    public function serviceAdditions(): HasMany
    {
        return $this->hasMany(\Modules\Service\Models\ServiceAddition::class);
    }

    /**
     * Get categories using this icon
     */
    public function categories(): HasMany
    {
        return $this->hasMany(\Modules\Category\Models\Category::class);
    }
}
