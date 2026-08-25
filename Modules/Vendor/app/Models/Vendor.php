<?php

namespace Modules\Vendor\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Spatie\Translatable\HasTranslations;

class Vendor extends Model
{
    use Cacheable;
    use HasFactory, HasTranslations, Notifiable;
    use HasSoftDeletes;

    protected $fillable = [
        'name',
        'logo',
        'email',
        'phone',
        'otp_code',
        'otp_expires_at',
        'vat_number',
        'official_number',
        'delivery_price_per_km',
        'wallet_balance',
        'attachments',
        'is_active',
        'is_verified',
        'rejection_reason',
        'rejected_at',
        'is_banned',
        'ban_reason',
        'banned_at',
    ];

    public array $translatable = ['name'];

    protected $casts = [
        'delivery_price_per_km' => 'decimal:2',
        'wallet_balance' => 'decimal:2',
        'attachments' => 'array',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'rejected_at' => 'datetime',
        'is_banned' => 'boolean',
        'otp_expires_at' => 'datetime',
        'banned_at' => 'datetime',
    ];

    /**
     * Boot method to auto-create owner employee when vendor is created
     * and flush cache tags on update.
     */
    protected static function booted(): void
    {
        static::created(function (Vendor $vendor) {
            app(\Modules\Vendor\Services\VendorDefaultRolesService::class)->seedForVendor($vendor);
            $vendor->createOwnerEmployee();
        });

        static::saved(fn () => self::clearCache());
        static::deleted(fn () => self::clearCache());
    }

    public static function clearCache(): void
    {
        flushCacheTags(['vendors', 'branches']);
    }

    /**
     * Create owner employee for this vendor
     */
    public function createOwnerEmployee(): ?VendorEmployee
    {
        // Check if owner already exists for this vendor
        $existingOwner = $this->employees()->where('role', 'owner')->first();
        if ($existingOwner) {
            return $existingOwner;
        }

        // Check if an employee with this phone already exists for this vendor
        $existingEmployee = VendorEmployee::where('phone', $this->phone)
            ->where('vendor_id', $this->id)
            ->first();

        if ($existingEmployee) {
            // Update existing employee to be owner with vendor's email
            $existingEmployee->update([
                'role' => 'owner',
                'email' => $this->email,
                'name' => \is_array($this->name)
                    ? ($this->name['en'] ?? $this->name['ar'] ?? 'Vendor Owner')
                    : ($this->name ?? 'Vendor Owner'),
                'is_active' => true,
                'is_verified' => true,
            ]);

            return $existingEmployee;
        }

        // Check if email is already used by another vendor's employee
        $emailExists = VendorEmployee::where('email', $this->email)
            ->where('vendor_id', '!=', $this->id)
            ->exists();

        // Get vendor name (handle translatable)
        $vendorName = \is_array($this->name)
            ? ($this->name['en'] ?? $this->name['ar'] ?? 'Vendor Owner')
            : ($this->name ?? 'Vendor Owner');

        // If email is taken, use a unique email format: vendor{id}_{original_email}
        $employeeEmail = $emailExists
            ? "vendor{$this->id}_{$this->email}"
            : $this->email;

        $employee = VendorEmployee::create([
            'vendor_id' => $this->id,
            'branch_id' => null,
            'name' => $vendorName,
            'email' => $employeeEmail,
            'phone' => $this->phone,
            'password' => Hash::make(Str::password(16)),
            'role' => 'owner',
            'is_active' => true,
            'is_verified' => true,
        ]);

        // TODO: In production, send generated credentials via SMS/email
        return $employee;
    }

    /**
     * Vendor catalog: system services the vendor has adopted (before assigning to branches).
     */
    public function catalogServices()
    {
        return $this->belongsToMany(
            Service::class,
            'vendor_service',
            'vendor_id',
            'service_id'
        )->withPivot('name', 'description', 'icon_id', 'is_active')
            ->withTimestamps();
    }

    /**
     * @alias catalogServices()
     */
    public function services()
    {
        return $this->catalogServices();
    }

    /**
     * Get all drivers that belong to this vendor
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(\Modules\Driver\Models\Driver::class);
    }

    /**
     * Get all pieces that belong to this vendor
     */
    public function pieces(): HasMany
    {
        return $this->hasMany(Piece::class);
    }

    /**
     * Get all branches that belong to this vendor
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Eager load active branches and their services for public laundry listing/detail APIs.
     *
     * @param  Builder<Vendor>  $query
     * @return Builder<Vendor>
     */
    public function scopeWithLaundryListingRelations(Builder $query): Builder
    {
        return $query->with([
            'branches' => function ($q) {
                $q->where('is_active', true)
                    ->with(['services' => function ($svc) {
                        $svc->select('services.id', 'services.service_name', 'services.description', 'services.icon', 'services.icon_id', 'services.is_active');
                    }]);
            },
        ]);
    }

    /**
     * Get the zone this vendor belongs to.
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(\Modules\Zone\Models\Zone::class);
    }

    /**
     * Get all employees for this vendor
     */
    public function employees(): HasMany
    {
        return $this->hasMany(VendorEmployee::class);
    }

    public function vendorRoles(): HasMany
    {
        return $this->hasMany(VendorRole::class);
    }

    /**
     * Get owner employee for this vendor
     */
    public function owner(): ?VendorEmployee
    {
        return $this->employees()->where('role', 'owner')->first();
    }

    /**
     * Get managers for this vendor
     */
    public function managers()
    {
        return $this->employees()->where('role', 'manager')->get();
    }

    /**
     * Get service additions (additional services) for this vendor
     */
    public function serviceAdditions(): HasMany
    {
        return $this->hasMany(\Modules\Service\Models\ServiceAddition::class);
    }

    /**
     * Sync all vendor services to all branches
     * Optionally specify which branches to sync to
     *
     * @param  array<int>|null  $branchIds
     */
    public function syncServicesToBranches(?array $branchIds = null): void
    {
        $branches = $branchIds
            ? $this->branches()->whereIn('id', $branchIds)->get()
            : $this->branches;

        foreach ($branches as $branch) {
            $branch->syncServicesFromVendor();
        }
    }

    /**
     * Sync all vendor pieces to all branches
     * Optionally specify which branches to sync to
     *
     * @param  array<int>|null  $branchIds
     */
    public function syncPiecesToBranches(?array $branchIds = null): void
    {
        $branches = $branchIds
            ? $this->branches()->whereIn('id', $branchIds)->get()
            : $this->branches;

        foreach ($branches as $branch) {
            $branch->syncPiecesFromVendor();
        }
    }

    /**
     * Get branches available in a specific zone
     */
    public function branchesInZone(int $zoneId)
    {
        return $this->branches()->where('zone_id', $zoneId)->where('is_active', true);
    }

    /**
     * Get translated vendor name
     *
     * @param  string|null  $lang  Language code (default: current locale)
     */
    public function getTranslatedName(?string $lang = null): string
    {
        if (! $this->name) {
            return '';
        }

        // If no language specified, use current locale
        if ($lang === null) {
            $lang = app()->getLocale();
        }

        // Use Spatie Translatable getTranslation
        if (method_exists($this, 'getTranslation')) {
            $translated = $this->getTranslation('name', $lang);
            if ($translated && $translated !== '[object Object]') {
                return $translated;
            }
        }

        // Fallback to array access
        if (\is_array($this->name)) {
            $value = $this->name[$lang] ?? $this->name['en'] ?? $this->name['ar'] ?? '';
            if ($value && $value !== '[object Object]') {
                return $value;
            }
        }

        // If it's a string and not "[object Object]", return as is
        if (\is_string($this->name) && $this->name !== '[object Object]') {
            return $this->name;
        }

        return '';
    }
}
