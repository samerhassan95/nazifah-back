<?php

namespace Modules\Vendor\Models;

use App\Traits\Cacheable;
use App\Traits\HasFcmTokens;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Branch\Models\Branch;
use Modules\Vendor\Services\VendorSystemRolesCatalog;

class VendorEmployee extends Authenticatable
{
    use Cacheable;
    use HasApiTokens, HasFactory, HasFcmTokens, Notifiable;
    use HasSoftDeletes;

    protected $fillable = [
        'vendor_id',
        'branch_id',
        'vendor_role_id',
        'name',
        'email',
        'phone',
        'password',
        'image',
        'role',
        'otp_code',
        'otp_expires_at',
        'is_verified',
        'is_active',
        'is_banned',
        'ban_reason',
        'banned_at',
    ];

    protected $hidden = [
        'password',
        'otp_code',
        'otp_expires_at',
    ];

    protected $casts = [
        'otp_expires_at' => 'datetime',
        'banned_at' => 'datetime',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'is_banned' => 'boolean',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::saving(function (VendorEmployee $employee) {
            if ($employee->phone) {
                $employee->phone = normalizePhone($employee->phone);
            }
        });
    }

    public static function findByPhone(string $phone): ?self
    {
        return static::query()->byPhone($phone)->first();
    }

    public function scopeByPhone($query, string $phone)
    {
        $normalized = normalizePhone($phone);

        return $query->whereRaw(
            "REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = ?",
            [$normalized]
        );
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vendorRole(): BelongsTo
    {
        return $this->belongsTo(VendorRole::class, 'vendor_role_id');
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(VendorEmployeeBranchAssignment::class, 'vendor_employee_id');
    }

    public function isOtpValid(): bool
    {
        return $this->otp_expires_at && $this->otp_expires_at->isFuture();
    }

    public function generateOtp(int $length = 5): string
    {
        $otp = str_pad((string) random_int(0, 99999), $length, '0', STR_PAD_LEFT);

        $this->update([
            'otp_code' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        return $otp;
    }

    public function verifyOtp(string $code): bool
    {
        if (! $this->isOtpValid()) {
            return false;
        }

        if ($this->otp_code === $code) {
            $this->update([
                'otp_code' => null,
                'otp_expires_at' => null,
                'is_verified' => true,
            ]);

            return true;
        }

        return false;
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function hasManagementPermissions(): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        if ($this->hasVendorPermission('manage_employees')) {
            return true;
        }

        return in_array($this->role, ['manager']);
    }

    public function hasVendorPermission(string $permission, ?int $branchId = null): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        if ($branchId !== null) {
            if (! $this->canAccessBranch($branchId)) {
                return false;
            }

            $role = $this->resolveRoleForBranch($branchId);

            return $role ? $role->hasPermission($permission) : false;
        }

        foreach ($this->getAccessibleBranchIds() as $accessibleBranchId) {
            $role = $this->resolveRoleForBranch($accessibleBranchId);
            if ($role && $role->hasPermission($permission)) {
                return true;
            }
        }

        if ($this->getAccessibleBranchIds() === [] && $this->vendor_role_id) {
            return $this->vendorRole?->hasPermission($permission) ?? false;
        }

        if ($this->getAccessibleBranchIds() === [] && ! $this->vendor_role_id) {
            return $this->resolveLegacyPermission($permission);
        }

        return false;
    }

    public function canAccessBranch(int $branchId): bool
    {
        if ($this->isOwner()) {
            return Branch::where('id', $branchId)
                ->where('vendor_id', $this->vendor_id)
                ->exists();
        }

        $accessibleBranchIds = $this->getAccessibleBranchIds();

        if ($accessibleBranchIds === []) {
            return false;
        }

        return in_array($branchId, $accessibleBranchIds, true);
    }

    public function getAccessibleBranchIds(): array
    {
        if ($this->isOwner()) {
            return Branch::where('vendor_id', $this->vendor_id)->pluck('id')->all();
        }

        try {
            $this->loadMissing('branchAssignments');

            if ($this->branchAssignments->isNotEmpty()) {
                return $this->branchAssignments->pluck('branch_id')->unique()->values()->all();
            }
        } catch (\Throwable) {
            // RBAC tables may be missing on older deployments.
        }

        if ($this->branch_id) {
            return [(int) $this->branch_id];
        }

        return [];
    }

    public function getPermissionsForBranch(?int $branchId = null): array
    {
        if ($this->isOwner()) {
            try {
                return \Modules\Vendor\Models\VendorPermission::pluck('name')->all();
            } catch (\Throwable) {
                return [];
            }
        }

        if ($branchId !== null) {
            return $this->resolveRoleForBranch($branchId)
                ?->permissions()->pluck('name')->all() ?? [];
        }

        if ($this->vendor_role_id && $this->vendorRole) {
            return $this->vendorRole->permissions()->pluck('name')->all();
        }

        $permissions = collect();

        foreach ($this->getAccessibleBranchIds() as $accessibleBranchId) {
            $role = $this->resolveRoleForBranch($accessibleBranchId);
            if ($role) {
                $permissions = $permissions->merge($role->permissions()->pluck('name'));
            }
        }

        if ($permissions->isEmpty() && $this->vendor_role_id) {
            $permissions = collect($this->vendorRole?->permissions()->pluck('name') ?? []);
        }

        return $permissions->unique()->values()->all();
    }

    public function getAccessPayload(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $branchAssignments = collect();
        $primaryRole = null;

        try {
            $this->loadMissing(['branchAssignments.branch', 'branchAssignments.role.permissions', 'vendorRole.permissions']);
            $branchAssignments = $this->branchAssignments;
            $primaryRole = $this->vendorRole;
        } catch (\Throwable) {
            try {
                $this->loadMissing('vendorRole.permissions');
                $primaryRole = $this->vendorRole;
            } catch (\Throwable) {
                $primaryRole = null;
            }
        }

        $branchAccess = $branchAssignments->map(function (VendorEmployeeBranchAssignment $assignment) {
            return [
                'branch_id' => $assignment->branch_id,
                'branch_name' => $assignment->branch?->name,
            ];
        })->values()->all();

        return [
            'is_owner' => $this->isOwner(),
            'role' => $primaryRole ? [
                'id' => $primaryRole->id,
                'name' => $primaryRole->name,
                'display_name' => $primaryRole->getDisplayName($locale),
            ] : null,
            'accessible_branch_ids' => $this->getAccessibleBranchIds(),
            'branches' => $branchAccess,
            'permissions' => $primaryRole
                ? $primaryRole->permissions->pluck('name')->values()->all()
                : $this->getPermissionsForBranch(),
        ];
    }

    public function syncBranchAssignments(array $assignments): void
    {
        if ($assignments === []) {
            return;
        }

        $vendorRoleId = (int) $assignments[0]['vendor_role_id'];

        $this->branchAssignments()->delete();

        foreach ($assignments as $assignment) {
            $this->branchAssignments()->create([
                'branch_id' => $assignment['branch_id'],
                'vendor_role_id' => $vendorRoleId,
            ]);
        }

        $this->unsetRelation('branchAssignments');

        $this->update([
            'branch_id' => $assignments[0]['branch_id'],
            'vendor_role_id' => $vendorRoleId,
            'role' => static::legacyRoleEnumForVendorRoleId($vendorRoleId),
        ]);
    }

    public function reconcilePrimaryRoleFromAssignments(): void
    {
        $this->loadMissing('branchAssignments');

        if ($this->branchAssignments->isEmpty()) {
            return;
        }

        $roleId = (int) $this->branchAssignments->first()->vendor_role_id;
        $branchId = (int) $this->branchAssignments->first()->branch_id;

        if ((int) $this->vendor_role_id === $roleId && (int) $this->branch_id === $branchId) {
            return;
        }

        $this->update([
            'branch_id' => $branchId,
            'vendor_role_id' => $roleId,
            'role' => static::legacyRoleEnumForVendorRoleId($roleId),
        ]);

        $this->unsetRelation('vendorRole');
    }

    public function getPrimaryVendorRole(): ?VendorRole
    {
        $this->loadMissing('vendorRole');

        return $this->vendorRole;
    }

    public function getApiRoleName(): string
    {
        if ($this->isOwner()) {
            return 'owner';
        }

        return $this->getPrimaryVendorRole()?->name ?? $this->role;
    }

    public static function legacyRoleEnumForVendorRoleId(?int $vendorRoleId): string
    {
        if (! $vendorRoleId) {
            return 'employee';
        }

        $role = VendorRole::find($vendorRoleId);
        if ($role && VendorSystemRolesCatalog::usesManagerLegacyEnum($role->name)) {
            return 'manager';
        }

        if ($role && $role->name === 'manager' && $role->is_system) {
            return 'manager';
        }

        return 'employee';
    }

    public function scopeByVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeByBranch($query, int $branchId)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->where('branch_id', $branchId)
                ->orWhereHas('branchAssignments', function ($assignmentQuery) use ($branchId) {
                    $assignmentQuery->where('branch_id', $branchId);
                });
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_banned', false);
    }

    /**
     * Employees who should receive order notifications for a branch (owner + branch access).
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function notifiableForOrderBranch(int $vendorId, int $branchId): \Illuminate\Support\Collection
    {
        if ($vendorId <= 0 || $branchId <= 0) {
            return collect();
        }

        return static::query()
            ->active()
            ->with(['branchAssignments'])
            ->where('vendor_id', $vendorId)
            ->get()
            ->filter(fn (self $employee) => $employee->canAccessBranch($branchId))
            ->unique('id')
            ->values();
    }

    private function resolveRoleForBranch(int $branchId): ?VendorRole
    {
        if (! $this->canAccessBranch($branchId)) {
            return null;
        }

        $this->loadMissing('vendorRole.permissions');

        if ($this->vendor_role_id && $this->vendorRole) {
            return $this->vendorRole;
        }

        return $this->resolveLegacyRole();
    }

    private function resolveLegacyRole(): ?VendorRole
    {
        if ($this->vendor_role_id) {
            $this->loadMissing('vendorRole');

            return $this->vendorRole;
        }

        if ($this->role === 'manager') {
            return VendorRole::where('vendor_id', $this->vendor_id)
                ->where('name', 'branch_manager')
                ->where('is_system', true)
                ->first()
                ?? VendorRole::where('vendor_id', $this->vendor_id)
                    ->where('name', 'manager')
                    ->where('is_system', true)
                    ->first();
        }

        if ($this->role === 'employee') {
            return VendorRole::where('vendor_id', $this->vendor_id)
                ->where('name', 'customer_support')
                ->where('is_system', true)
                ->first()
                ?? VendorRole::where('vendor_id', $this->vendor_id)
                    ->where('name', 'employee')
                    ->where('is_system', true)
                    ->first();
        }

        return null;
    }

    private function resolveLegacyPermission(string $permission): bool
    {
        if ($this->role === 'manager') {
            return ! in_array($permission, ['manage_roles', 'manage_employees', 'edit_vendor_profile', 'manage_subscriptions'], true);
        }

        if ($this->role === 'employee') {
            return str_starts_with($permission, 'view_');
        }

        return false;
    }
}
