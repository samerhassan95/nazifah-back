<?php

namespace Modules\Category\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Every service associated with this category via category_service, in
     * addition to the services whose single required category_id points here
     * (see services() above). A service like "Regular Washing" can show up
     * under more than one category through this relation.
     */
    public function linkedServices(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'category_service')
            ->withTimestamps();
    }

    /**
     * Get the icon associated with this category
     */
    public function iconRelation()
    {
        return $this->belongsTo(\Modules\Admin\Models\Icon::class, 'icon_id');
    }

    /**
     * Get all active services for this category (direct category_id + many-to-many pivot)
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveServices()
    {
        return Service::where(function ($q) {
            $q->where('category_id', $this->id)
                ->orWhereHas('categories', fn ($cq) => $cq->where('categories.id', $this->id));
        })->where('is_active', true)->get();
    }

    /**
     * Get all services query for this category (direct category_id + pivot)
     */
    public function allServices()
    {
        return Service::where(function ($q) {
            $q->where('category_id', $this->id)
                ->orWhereHas('categories', fn ($cq) => $cq->where('categories.id', $this->id));
        });
    }
}
