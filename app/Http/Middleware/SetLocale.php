<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get available locales from config
        $availableLocales = config('app.available_locales', ['ar', 'en']);
        $defaultLocale = config('app.locale', 'ar');

        // Get locale from Accept-Language header
        $acceptLanguage = $request->header('Accept-Language');
        $locale = $defaultLocale; // Default to Arabic

        if ($acceptLanguage) {
            // Parse Accept-Language header (e.g., "ar", "ar-SA", "ar,en;q=0.9", "en-US,en;q=0.9,ar;q=0.8")
            $languages = [];
            $parts = explode(',', $acceptLanguage);

            foreach ($parts as $part) {
                $part = trim($part);
                // Extract language code (e.g., "ar" from "ar-SA" or "ar;q=0.9")
                if (preg_match('/^([a-z]{2})(?:[-_][a-z]{2,})?(?:;q=([0-9.]+))?$/i', $part, $matches)) {
                    $langCode = strtolower($matches[1]);
                    $quality = isset($matches[2]) ? (float) $matches[2] : 1.0;
                    $languages[$langCode] = $quality;
                }
            }

            // Sort by quality (preference)
            arsort($languages);

            // Find the first matching locale
            foreach (array_keys($languages) as $langCode) {
                if (in_array($langCode, $availableLocales)) {
                    $locale = $langCode;
                    break;
                }
            }
        }

        // Set the locale
        app()->setLocale($locale);

        return $next($request);
    }
}
