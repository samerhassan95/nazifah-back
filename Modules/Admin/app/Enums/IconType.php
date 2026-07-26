<?php

namespace Modules\Admin\Enums;

enum IconType: string
{
    case CATEGORY = 'category';
    case SERVICE = 'service';
    case ADDITIONS = 'additions';
    case PIECE = 'piece';

    /**
     * Get all enum values as an array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all enum values as a comma-separated string for validation
     */
    public static function valuesString(): string
    {
        return implode(',', self::values());
    }
}
