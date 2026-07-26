<?php

namespace Modules\Notification\Enums;

enum UserTargetType: string
{
    case CLIENT = 'client';
    case DRIVER = 'driver';
    case VENDOR = 'vendor';
    case ALL = 'all';
    case SPECIFIC_USERS = 'specific_users';
    case SPECIFIC_GROUPS = 'specific_groups';
    case SEGMENT = 'segment';

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
            self::SPECIFIC_USERS => 'Specific Users',
            self::SPECIFIC_GROUPS => 'Specific Groups',
            self::SEGMENT => 'Segment',
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
            self::SPECIFIC_USERS => 'مستخدمين محددين',
            self::SPECIFIC_GROUPS => 'مجموعات محددة',
            self::SEGMENT => 'شريحة',
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
            default => '',
        };
    }
}
