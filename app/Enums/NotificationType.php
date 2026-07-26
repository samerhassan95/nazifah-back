<?php

namespace App\Enums;

enum NotificationType: string
{
    case SYSTEM = 'system';
    case ORDERS = 'orders';
    case FINANCES = 'finances';
    case ORDER_REMINDER = 'order_reminder';
    case ORDER_PAYMENT_COMPLETED = 'order_payment_completed';

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
            self::ORDER_REMINDER => 'Order Reminder',
            self::ORDER_PAYMENT_COMPLETED => 'Order Payment Completed',
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
            self::ORDER_REMINDER => 'تذكير بالطلب',
            self::ORDER_PAYMENT_COMPLETED => 'تم الدفع',
        };
    }
}
