<?php

namespace Modules\Notification\Enums;

enum NotificationType: string
{
    case SYSTEM = 'system';
    case ORDERS = 'orders';
    case FINANCES = 'finances';

    /**
     * Get all notification type values
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get notification type label
     */
    public function label(): string
    {
        return match ($this) {
            self::SYSTEM => 'System',
            self::ORDERS => 'Orders',
            self::FINANCES => 'Finances',
        };
    }

    /**
     * Get notification type label in Arabic
     */
    public function labelAr(): string
    {
        return match ($this) {
            self::SYSTEM => 'النظام',
            self::ORDERS => 'الطلبات',
            self::FINANCES => 'المالية',
        };
    }
}
