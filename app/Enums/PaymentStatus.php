<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case COMPLETED = 'completed';
    case PENDING = 'pending';
    case FAILED = 'failed';
    case NOT_INITIATED = 'not_initiated';

    /**
     * Get all enum values
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => 'Completed',
            self::PENDING => 'Pending',
            self::FAILED => 'Failed',
            self::NOT_INITIATED => 'Not Initiated',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::COMPLETED => 'مكتمل',
            self::PENDING => 'قيد الانتظار',
            self::FAILED => 'فاشل',
            self::NOT_INITIATED => 'لم يبدأ',
        };
    }

    public function localizedLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? $this->labelAr() : $this->label();
    }
}
