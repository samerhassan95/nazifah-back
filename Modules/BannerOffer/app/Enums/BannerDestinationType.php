<?php

namespace Modules\BannerOffer\Enums;

enum BannerDestinationType: string
{
    case VENDOR = 'vendor';
    case CATEGORY = 'category';
    case SERVICE = 'service';
    case COUPON = 'coupon';
    case PAYMENT_METHOD = 'payment_method';
    case EXTERNAL_URL = 'external_url';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getModelClass(): ?string
    {
        return match ($this) {
            self::VENDOR => \Modules\Vendor\Models\Vendor::class,
            self::CATEGORY => \Modules\Category\Models\Category::class,
            self::SERVICE => \Modules\Service\Models\Service::class,
            self::COUPON => \Modules\Discount\Models\Discount::class,
            self::PAYMENT_METHOD => \Modules\Payment\Models\PaymentMethod::class,
            self::EXTERNAL_URL => null,
        };
    }
}
