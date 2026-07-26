<?php

namespace App\Support;

class NotificationLocale
{
    public static function normalize(?string $lang): string
    {
        return in_array($lang, ['ar', 'en'], true) ? $lang : 'ar';
    }

    public static function pick(string $ar, string $en, ?string $lang): string
    {
        return self::normalize($lang) === 'ar' ? $ar : $en;
    }

    /**
     * @param  array<string, string>|string|null  $translations
     */
    public static function fromTranslations(array|string|null $translations, ?string $lang): string
    {
        if (is_string($translations)) {
            return $translations;
        }

        if (! is_array($translations)) {
            return '';
        }

        $normalized = self::normalize($lang);

        return $translations[$normalized]
            ?? $translations['ar']
            ?? $translations['en']
            ?? '';
    }
}
