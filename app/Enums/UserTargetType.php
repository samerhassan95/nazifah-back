<?php

namespace App\Enums;

enum UserTargetType: string
{
    case CLIENT = 'client';
    case DRIVER = 'driver';
    case VENDOR = 'vendor';
    case ALL = 'all';

    /**
     * Get all user target type values
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get user target type label
     */
    public function label(): string
    {
        return match ($this) {
            self::CLIENT => 'Client',
            self::DRIVER => 'Driver',
            self::VENDOR => 'Vendor',
            self::ALL => 'All Users',
        };
    }

    /**
     * Get user target type label in Arabic
     */
    public function labelAr(): string
    {
        return match ($this) {
            self::CLIENT => 'عميل',
            self::DRIVER => 'سائق',
            self::VENDOR => 'مغسلة',
            self::ALL => 'جميع المستخدمين',
        };
    }

    /**
     * Get the model class for this user type
     */
    public function getModelClass(): string
    {
        return match ($this) {
            self::CLIENT => \Modules\Client\Models\Client::class,
            self::DRIVER => \Modules\Driver\Models\Driver::class,
            self::VENDOR => \Modules\Vendor\Models\Vendor::class,
            self::ALL => '',
        };
    }
}
