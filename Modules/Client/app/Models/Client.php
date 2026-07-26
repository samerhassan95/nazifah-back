<?php

namespace Modules\Client\Models;

use App\Traits\Cacheable;
use App\Traits\HasFcmTokens;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Address\Models\Address;
use Modules\Order\Models\Order;
use Modules\Vendor\Models\Vendor;

class Client extends Authenticatable
{
    use Cacheable;
    use HasApiTokens, HasFactory, HasFcmTokens, Notifiable;
    use HasSoftDeletes;

    protected static function newFactory(): \Database\Factories\ClientFactory
    {
        return \Database\Factories\ClientFactory::new();
    }

    /**
     * Get translation for a field.
     * Manual implementation to avoid Spatie trait issues on non-JSON columns.
     */
    public function getTranslation(string $field, string $locale): string
    {
        if ($field === 'full_name') {
            $value = $this->getAttributes()['full_name'] ?? null;
            if (! $value) {
                return 'Customer';
            }

            if (! $this->isJson($value)) {
                return $value;
            }

            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded[$locale] ?? $decoded['en'] ?? $decoded['ar'] ?? 'Customer';
            }

            return $value;
        }

        return $this->{$field} ?? '';
    }

    protected $fillable = [
        'phone',
        'fingerprint',
        'full_name',
        'email',
        'image',
        'otp_code',
        'otp_expires_at',
        'is_verified',
        'is_active',
        'is_banned',
        'ban_reason',
        'banned_at',
    ];

    protected $hidden = [
        'otp_code',
        'otp_expires_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'is_banned' => 'boolean',
        'banned_at' => 'datetime',
        'full_name' => 'string',
    ];

    /**
     * Get the full name attribute.
     * Ensures it's always returned as a string, even if stored as JSON.
     */
    public function getFullNameAttribute($value)
    {
        // If it's already a string, return it
        if (is_string($value) && ! $this->isJson($value)) {
            return $value;
        }

        // If it's JSON, decode and extract the name
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded['en'] ?? $decoded['ar'] ?? 'Customer';
        }

        return $value ?? 'Customer';
    }

    /**
     * Check if a string is JSON
     */
    private function isJson($string)
    {
        if (! is_string($string)) {
            return false;
        }
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
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
                'is_verified' => true,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get all addresses for this client
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Get all orders for this client
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * PayFort-tokenized cards saved for this client.
     */
    public function cards(): HasMany
    {
        return $this->hasMany(ClientCard::class);
    }

    /**
     * Get default address for this client
     */
    public function defaultAddress()
    {
        return $this->addresses()->where('is_default', true)->first();
    }

    public function getDefaultAddressText(): ?string
    {
        $defaultAddr = $this->defaultAddress();
        if (! $defaultAddr) {
            return null;
        }

        $text = $defaultAddr->address_text ?? $defaultAddr->street_name;

        if ($text && $defaultAddr->national_address) {
            $text = $this->stripNationalAddressPrefix($text, $defaultAddr->national_address);
        }

        $text = is_string($text) ? trim($text) : null;

        if ($text !== null && $text !== '') {
            return $text;
        }

        return $this->buildAddressTextFromParts($defaultAddr);
    }

    public function getDefaultNationalAddress(): ?string
    {
        $nationalAddress = $this->defaultAddress()?->national_address;

        return is_string($nationalAddress) && trim($nationalAddress) !== ''
            ? trim($nationalAddress)
            : null;
    }

    protected function stripNationalAddressPrefix(string $text, string $nationalAddress): string
    {
        $nationalAddress = trim($nationalAddress);
        if ($nationalAddress === '') {
            return trim($text);
        }

        if (trim($text) === $nationalAddress) {
            return '';
        }

        $quoted = preg_quote($nationalAddress, '/');
        $stripped = preg_replace('/^'.$quoted.'\s*[،,]\s*/u', '', trim($text), 1);

        if ($stripped !== null && trim($stripped) !== trim($text)) {
            return trim($stripped);
        }

        $stripped = preg_replace('/^'.$quoted.'\s+/u', '', trim($text), 1);

        return $stripped !== null ? trim($stripped) : trim($text);
    }

    protected function buildAddressTextFromParts($address): ?string
    {
        $parts = array_filter([
            $address->street_name,
            $address->building_number,
            $address->district,
            $address->city,
            $address->postal_code,
        ], fn ($part) => is_string($part) && trim($part) !== '');

        return $parts !== [] ? implode('، ', $parts) : null;
    }

    /**
     * Standard client_info payload for order APIs (vendor/driver).
     */
    public function toApiClientInfo(?string $locale = null, ?string $imageUrl = null, array $extra = []): array
    {
        $locale = $locale ?? app()->getLocale();

        return array_merge([
            'id' => $this->id,
            'name' => $this->getTranslation('full_name', $locale) ?: ($this->full_name ?? 'Customer'),
            'phone_number' => $this->phone,
            'image' => $imageUrl ?? $this->image,
            'default_address_text' => $this->getDefaultAddressText(),
            'national_address' => $this->getDefaultNationalAddress(),
        ], $extra);
    }

    /**
     * Get client's zone based on default address
     */
    public function getClientZone()
    {
        $defaultAddress = $this->defaultAddress();

        return $defaultAddress ? $defaultAddress->zone : null;
    }

    /**
     * Get available vendors in client's zone
     */
    public function getAvailableVendors()
    {
        $zone = $this->getClientZone();

        if (! $zone) {
            return collect();
        }

        return Vendor::where('zone_id', $zone->id)
            ->where('is_active', true)
            ->with(['zone'])
            ->get();
    }

    /**
     * Get available vendors by category in client's zone
     */
    public function getAvailableVendorsByCategory($categoryId = null)
    {
        $zone = $this->getClientZone();

        if (! $zone) {
            return collect();
        }

        $query = Vendor::where('zone_id', $zone->id)
            ->where('is_active', true)
            ->with(['zone']);

        if ($categoryId) {
            // Filter vendors by category through their branches' services
            $query->whereHas('branches.services', function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId)
                    ->where('services.is_active', true);
            });
        }

        return $query->get();
    }

    /**
     * Get available branches in client's zone
     */
    public function getAvailableBranches()
    {
        $zone = $this->getClientZone();

        if (! $zone) {
            return collect();
        }

        return \Modules\Branch\Models\Branch::where('zone_id', $zone->id)
            ->where('is_active', true)
            ->whereHas('vendor', function ($query) {
                $query->where('is_active', true)->where('is_banned', false);
            })
            ->with(['vendor', 'zone'])
            ->get();
    }

    /**
     * Get available branches by category in client's zone
     * Note: Branches are now linked to categories through services
     */
    public function getAvailableBranchesByCategory($categoryId = null)
    {
        $zone = $this->getClientZone();

        if (! $zone) {
            return collect();
        }

        $query = \Modules\Branch\Models\Branch::where('zone_id', $zone->id)
            ->where('is_active', true)
            ->whereHas('vendor', function ($q) {
                $q->where('is_active', true)->where('is_banned', false);
            });

        if ($categoryId) {
            // Filter branches by category through their services
            $query->whereHas('services', function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId);
            });
        }

        $query->with(['vendor', 'zone']);

        return $query->get();
    }

    /**
     * Get available branches that offer a specific service in client's zone
     */
    public function getAvailableBranchesWithService(int $serviceId)
    {
        $zone = $this->getClientZone();

        if (! $zone) {
            return collect();
        }

        return \Modules\Branch\Models\Branch::getAvailableInZoneWithService($zone->id, $serviceId);
    }

    /**
     * Get available branches that offer specific services in client's zone
     */
    public function getAvailableBranchesWithServices(array $serviceIds)
    {
        $zone = $this->getClientZone();

        if (! $zone) {
            return collect();
        }

        return \Modules\Branch\Models\Branch::getAvailableInZoneWithServices($zone->id, $serviceIds);
    }

    /**
     * Get available branches with active delivery drivers in client's zone
     */
    public function getAvailableBranchesWithDrivers()
    {
        $zone = $this->getClientZone();

        if (! $zone) {
            return collect();
        }

        return \Modules\Branch\Models\Branch::where('zone_id', $zone->id)
            ->where('is_active', true)
            ->whereHas('vendor', function ($query) {
                $query->where('is_active', true)->where('is_banned', false);
            })
            ->withAvailableDrivers()
            ->with(['vendor', 'zone', 'availableDrivers'])
            ->get();
    }

    /**
     * Get branches for placing an order based on required services
     */
    public function getBranchesForOrder(array $serviceIds = [])
    {
        $zone = $this->getClientZone();

        if (! $zone) {
            return collect();
        }

        $query = \Modules\Branch\Models\Branch::where('zone_id', $zone->id)
            ->where('is_active', true)
            ->whereHas('vendor', function ($q) {
                $q->where('is_active', true)->where('is_banned', false);
            });

        if (! empty($serviceIds)) {
            $query->whereHas('services', function ($q) use ($serviceIds) {
                $q->whereIn('services.id', $serviceIds);
            });
        }

        return $query->with(['vendor', 'zone', 'services', 'pieces', 'availableDrivers'])->get();
    }
}
