<?php

namespace Modules\Payment\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Payment\Services\ActiveGatewayResolver;

class PaymentMethod extends Model
{
    use Cacheable;
    use HasFactory;
    use HasSoftDeletes;

    protected static function booted()
    {
        static::saved(fn () => self::clearCache());
        static::deleted(fn () => self::clearCache());
    }

    public static function clearCache(): void
    {
        flushCacheTags(['payment_methods']);
    }

    protected $fillable = [
        'method_key',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope to get only active payment methods
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order', 'asc');
    }

    /**
     * Get the enum instance for this payment method
     */
    public function getEnumInstance(): ?\App\Enums\PaymentMethod
    {
        try {
            return \App\Enums\PaymentMethod::from($this->method_key);
        } catch (\ValueError $e) {
            return null;
        }
    }

    /**
     * Get display name for this payment method
     */
    public function getDisplayName(?string $locale = null): string
    {
        $enum = $this->getEnumInstance();

        return $enum ? $enum->getDisplayName($locale) : $this->method_key;
    }

    /**
     * Get all active payment methods with details for the user app.
     *
     * Card/redirect methods use the admin-selected active gateway (amazon_pay | moyasar).
     */
    public static function getActiveWithDetails(?string $locale = null, ?string $activeGateway = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $activeGateway = $activeGateway ?? ActiveGatewayResolver::name();

        return self::active()
            ->get()
            ->map(fn (self $method) => self::formatForApi($method, $locale, $activeGateway))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * User-facing payload: active gateway + enabled methods.
     *
     * @return array{active_gateway: string, payment_methods: array<int, array<string, mixed>>}
     */
    public static function getActivePayloadForUser(?string $locale = null): array
    {
        $activeGateway = ActiveGatewayResolver::name();

        return [
            'active_gateway' => $activeGateway,
            'payment_methods' => self::getActiveWithDetails($locale, $activeGateway),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function formatForApi(self $method, string $locale, string $activeGateway): ?array
    {
        $enum = $method->getEnumInstance();

        if (! $enum) {
            return null;
        }

        $isInternal = in_array($method->method_key, ['cash_on_delivery', 'nazefah_wallet'], true);
        $gatewayKey = $isInternal ? $method->method_key : $activeGateway;

        $payload = [
            'id' => $method->id,
            'type' => $gatewayKey,
            'value' => $method->method_key,
            'label' => $enum->getDisplayName($locale),
            'is_active' => $method->is_active,
            'sort_order' => $method->sort_order,
        ];

        if ($isInternal) {
            $payload['payfort_option'] = null;
            $payload['moyasar_source'] = null;
        } elseif ($activeGateway === 'moyasar') {
            $payload['payfort_option'] = null;
            $payload['moyasar_source'] = $enum->getMoyasarSource();
        } else {
            $payload['payfort_option'] = $enum->getPayfortPaymentOption();
            $payload['moyasar_source'] = null;
        }

        return $payload;
    }

    /**
     * Get all payment methods (including inactive) with details for admin
     */
    public static function getAllWithDetails(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $activeGateway = ActiveGatewayResolver::name();

        return self::orderBy('sort_order', 'asc')
            ->get()
            ->map(fn (self $method) => self::formatForApi($method, $locale, $activeGateway))
            ->filter()
            ->values()
            ->all();
    }
}
