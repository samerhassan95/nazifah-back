<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeBooleanStrings
{
    /**
     * Convert the literal strings "true"/"false" (case-insensitive, exact match
     * only) in request input to real booleans, recursively.
     *
     * Laravel's `boolean` validation rule only accepts [true, false, 0, 1, '0', '1']
     * (strict in_array check) — it does NOT accept the strings "true"/"false".
     * Browser FormData sends a JS boolean's default toString(), which is exactly
     * "true"/"false", so any admin/dashboard checkbox posted via multipart form
     * data fails `boolean` validation outright (422) or, worse, silently casts
     * to true via PHP's truthy check on a non-empty string ("false" is truthy).
     * Normalizing here — once, globally — fixes every endpoint using `boolean`
     * validation instead of patching each FormRequest individually.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $normalize = function ($value) use (&$normalize) {
            if (is_array($value)) {
                return array_map($normalize, $value);
            }

            if (is_string($value)) {
                $lower = strtolower($value);
                if ($lower === 'true') {
                    return true;
                }
                if ($lower === 'false') {
                    return false;
                }
            }

            return $value;
        };

        $request->request->replace($normalize($request->request->all()));
        $request->query->replace($normalize($request->query->all()));

        return $next($request);
    }
}
