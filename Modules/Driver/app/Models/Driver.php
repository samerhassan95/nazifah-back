<?php

namespace Modules\Driver\Models;

use App\Enums\OrderStatus;
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
use Modules\Order\Models\Order;
use Modules\Payment\Models\DeliveryWallet;
use Modules\Vendor\Models\Vendor;
use Spatie\Translatable\HasTranslations;

class Driver extends Authenticatable
{
    use Cacheable;
    use HasApiTokens, HasFactory, HasFcmTokens, HasTranslations, Notifiable;
    use HasSoftDeletes;

    protected $fillable = [
        'vendor_id',
        'branch_id',
        'phone',
        'id_number',
        'image_document',
        'fingerprint',
        'full_name',
        'email',
        'image',
        'latitude',
        'longitude',
        'location',
        'is_available',
        'is_banned',
        'ban_reason',
        'banned_at',
        'rating',
        'total_orders',
        'otp_code',
        'otp_expires_at',
    ];

    public $translatable = ['full_name', 'location'];

    protected $hidden = [
        'otp_code',
        'otp_expires_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'is_available' => 'boolean',
        'is_banned' => 'boolean',
        'banned_at' => 'datetime',
        'latitude' => 'decimal:12',
        'longitude' => 'decimal:12',
        'rating' => 'decimal:2',
        'total_orders' => 'integer',
    ];

    /**
     * Get the branch that this driver belongs to (many-to-one relationship)
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the full URL for the image attribute.
     */
    public function getImageAttribute($value)
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http')) {
            return $value;
        }

        return config('app.url').$value;
    }

    public function isOtpValid(): bool
    {
        return $this->otp_expires_at && $this->otp_expires_at->isFuture();
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
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get active branch this driver is assigned to
     *
     * @deprecated A driver now has only one branch
     */
    public function activeBranches(): BelongsTo
    {
        return $this->branch();
    }

    /**
     * Get orders assigned to this driver
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get pending orders for this driver
     */
    public function pendingOrders(): HasMany
    {
        return $this->orders()->whereNotIn('status', [
            OrderStatus::DELIVERED->value,
            OrderStatus::COMPLETED->value,
            OrderStatus::CANCELLED->value,
        ]);
    }

    /**
     * Check if driver is assigned to a specific branch
     */
    public function isAssignedToBranch(int $branchId): bool
    {
        return $this->branch_id == $branchId;
    }

    /**
     * Get the primary branch for this driver
     */
    public function primaryBranch(): BelongsTo
    {
        return $this->branch();
    }

    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeliveryWallet::class);
    }

    public function getOrCreateWallet(): DeliveryWallet
    {
        return $this->wallet ?? $this->wallet()->create([
            'balance' => 0,
            'hold_amount' => 0,
        ]);
    }

    /**
     * Get default address for this driver
     * Note: Drivers don't have addresses like clients do.
     * This method returns null for compatibility with code that expects it.
     */
    public function defaultAddress()
    {
        return null;
    }
}
