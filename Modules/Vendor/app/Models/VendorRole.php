<?php

namespace Modules\Vendor\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorRole extends Model
{
    use Cacheable;
    use HasFactory;
    use HasSoftDeletes;

    protected $fillable = [
        'vendor_id',
        'name',
        'display_name_ar',
        'display_name_en',
        'description_ar',
        'description_en',
        'is_active',
        'is_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(VendorPermission::class, 'vendor_role_permission', 'vendor_role_id', 'vendor_permission_id')
            ->withTimestamps();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(VendorEmployee::class, 'vendor_role_id');
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(VendorEmployeeBranchAssignment::class, 'vendor_role_id');
    }

    public function hasPermission(string $permissionName): bool
    {
        return $this->permissions()->where('name', $permissionName)->exists();
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
}
