<?php

namespace Modules\Vendor\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VendorPermission extends Model
{
    use Cacheable;
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name_ar',
        'display_name_en',
        'group',
        'description_ar',
        'description_en',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(VendorRole::class, 'vendor_role_permission', 'vendor_permission_id', 'vendor_role_id')
            ->withTimestamps();
    }

    public function getDisplayName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'ar' ? $this->display_name_ar : $this->display_name_en;
    }

    public function getDescription(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'ar' ? $this->description_ar : $this->description_en;
    }

    public function scopeByGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    public static function getAllGrouped(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();

        return self::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy('group')
            ->map(function ($groupPermissions) use ($locale) {
                return $groupPermissions->map(function ($permission) use ($locale) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'display_name' => $permission->getDisplayName($locale),
                        'description' => $permission->getDescription($locale),
                    ];
                })->values();
            })
            ->toArray();
    }
}
