<?php

namespace Modules\Category\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Service\Models\Service;
use Spatie\Translatable\HasTranslations;

class Category extends Model
{
    use Cacheable;
    use HasSoftDeletes;
    use HasTranslations;

    protected static function booted()
    {
        static::saved(fn ($category) => self::clearCache($category));
        static::deleted(fn ($category) => self::clearCache($category));
    }

    public static function clearCache(Category $category): void
    {
        flushCacheTags(['categories', 'departments']);
        flushCacheTags("category_{$category->id}");
    }

    protected $fillable = [
        'name',
        'description',
        'icon_id',
        'image',
        'order',
        'is_active',
    ];

    public array $translatable = ['name', 'description'];

    protected $casts = [
        'order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the icon associated with this category
     */
    public function iconRelation()
    {
        return $this->belongsTo(\Modules\Admin\Models\Icon::class, 'icon_id');
    }

    /**
     * Get all active services for this category
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveServices()
    {
        return $this->services()->get();
    }
}
