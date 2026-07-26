<?php

namespace Modules\Admin\Models;

use App\Traits\Cacheable;
use App\Traits\HasFcmTokens;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use Cacheable;
    use HasApiTokens, HasFactory, HasFcmTokens, Notifiable;
    use HasSoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'image',
        'password',
        'role_id',
        'otp_code',
        'otp_expires_at',
        'is_verified',
        'last_login_at',
        'permissions',
    ];

    protected $hidden = [
        'password',
        'otp_code',
        'otp_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'otp_expires_at' => 'datetime',
            'is_verified' => 'boolean',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'permissions' => 'array',
        ];
    }

    /**
     * Get the role of the admin
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Check if admin has a specific permission
     * Checks both role permissions and individual permissions
     */
    public function hasPermission(string $permissionName): bool
    {
        // Super admin has all permissions
        if ($this->role && $this->role->name === 'super_admin') {
            return true;
        }

        // Check role permissions
        if ($this->role && $this->role->hasPermission($permissionName)) {
            return true;
        }

        // Check individual permissions (legacy support)
        if ($this->permissions && is_array($this->permissions)) {
            return in_array($permissionName, $this->permissions);
        }

        return false;
    }

    /**
     * Check if admin has any of the given permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if admin has all of the given permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all permissions for this admin (from role and individual)
     */
    public function getAllPermissions(): array
    {
        $permissions = [];

        // Get permissions from role
        if ($this->role) {
            $rolePermissions = $this->role->permissions()->pluck('name')->toArray();
            $permissions = array_merge($permissions, $rolePermissions);
        }

        // Get individual permissions
        if ($this->permissions && is_array($this->permissions)) {
            $permissions = array_merge($permissions, $this->permissions);
        }

        return array_unique($permissions);
    }

    /**
     * Check if admin is super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->role && $this->role->name === 'super_admin';
    }

    public function isOtpValid(): bool
    {
        return $this->otp_expires_at && $this->otp_expires_at->isFuture();
    }

    public function scopeNotifiable($query)
    {
        return $query->where('is_verified', true);
    }

    public function generateOtp(int $length = 5): string
    {
        $otp = str_pad((string) random_int(0, 999999), $length, '0', STR_PAD_LEFT);

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
}
