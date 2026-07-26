<?php

namespace App\Enums;

enum PaymentTransactionStatus: string
{
    case COMPLETED = 'completed';
    case AUTHORIZED = 'authorized';
    case PENDING = 'pending';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case VOIDED = 'voided';
    case REFUNDED = 'refunded';
    case PARTIALLY_REFUNDED = 'partially_refunded';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => 'Completed',
            self::AUTHORIZED => 'Authorized',
            self::PENDING => 'Pending',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
            self::VOIDED => 'Voided',
            self::REFUNDED => 'Refunded',
            self::PARTIALLY_REFUNDED => 'Partially Refunded',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::COMPLETED => 'مكتمل',
            self::AUTHORIZED => 'محجوز',
            self::PENDING => 'قيد الانتظار',
            self::FAILED => 'فاشل',
            self::CANCELLED => 'ملغي',
            self::VOIDED => 'ملغى الحجز',
            self::REFUNDED => 'مسترد بالكامل',
            self::PARTIALLY_REFUNDED => 'مسترد جزئياً',
        };
    }

    public function localizedLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? $this->labelAr() : $this->label();
    }
}
