<?php

namespace Modules\Admin\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use Cacheable;
    use HasFactory;
    use HasSoftDeletes;

    protected $fillable = [
        'name',
        'display_name_ar',
        'display_name_en',
        'group',
        'description_ar',
        'description_en',
    ];

    /**
     * Get the roles that have this permission
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')
            ->withTimestamps();
    }

    /**
     * Get display name based on locale
     */
    public function getDisplayName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'ar' ? $this->display_name_ar : $this->display_name_en;
    }

    /**
     * Get description based on locale
     */
    public function getDescription(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'ar' ? $this->description_ar : $this->description_en;
    }

    /**
     * Scope to filter by group
     */
    public function scopeByGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Get all permissions grouped by group
     */
    public static function getAllGrouped(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $permissions = self::all();

        return $permissions->groupBy('group')->map(function ($groupPermissions) use ($locale) {
            return $groupPermissions->map(function ($permission) use ($locale) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->getDisplayName($locale),
                    'description' => $permission->getDescription($locale),
                ];
            });
        })->toArray();
    }
}
