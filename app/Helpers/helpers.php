<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

if (! function_exists('jsonResponse')) {
    /**
     * Returns a standardized JSON response.
     *
     * @param  bool  $status
     * @param  int  $code
     * @param  string|null  $message
     * @param  mixed  $data
     * @param  array|null  $meta
     * @param  array|null  $errors
     * @return \Illuminate\Http\JsonResponse
     */
    function jsonResponse($status, $code, $message = null, $data = null, $meta = null, $errors = null)
    {
        $response = [
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ];

        if (! is_null($data)) {
            $response['data'] = $data;
        }

        if (! $status && ! is_null($errors)) {
            $response['errors'] = $errors;
        }

        if (! is_null($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }
}

/**
 * Extract standard pagination meta from a paginator.
 */
function paginationMeta(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
{
    return [
        'current_page' => $paginator->currentPage(),
        'from' => $paginator->firstItem(),
        'last_page' => $paginator->lastPage(),
        'per_page' => $paginator->perPage(),
        'to' => $paginator->lastItem(),
        'total' => $paginator->total(),
    ];
}

/**
 * Returns success response
 */
function successResponse($data = null, $message = 'Success', $code = 200, $meta = null): JsonResponse
{
    // Handle Resource Collections with pagination
    if ($data instanceof \Illuminate\Http\Resources\Json\AnonymousResourceCollection) {
        $response = $data->response()->getData(true);

        // If it has pagination meta, extract it
        if (isset($response['meta'])) {
            $paginationMeta = [
                'current_page' => $response['meta']['current_page'] ?? null,
                'from' => $response['meta']['from'] ?? null,
                'last_page' => $response['meta']['last_page'] ?? null,
                'per_page' => $response['meta']['per_page'] ?? null,
                'to' => $response['meta']['to'] ?? null,
                'total' => $response['meta']['total'] ?? null,
            ];
            $meta = array_merge($paginationMeta, is_array($meta) ? $meta : []);
            $data = $response['data'];
        } elseif (isset($response['links'])) {
            $data = $response['data'];
        } else {
            // No pagination, just get the data
            $data = $response['data'] ?? $response;
        }
    }
    // Handle paginated data directly
    elseif ($data instanceof \Illuminate\Pagination\LengthAwarePaginator) {
        $paginationMeta = paginationMeta($data);
        $meta = array_merge($paginationMeta, is_array($meta) ? $meta : []);
        $data = $data->items();
    }
    // Handle array data with 'pagination' key - convert to 'meta'
    elseif (is_array($data) && isset($data['pagination'])) {
        $pagination = $data['pagination'];
        unset($data['pagination']);

        // Build meta from pagination data
        $paginationMeta = [
            'current_page' => $pagination['current_page'] ?? null,
            'from' => $pagination['from'] ?? null,
            'last_page' => $pagination['last_page'] ?? null,
            'per_page' => $pagination['per_page'] ?? null,
            'to' => $pagination['to'] ?? null,
            'total' => $pagination['total'] ?? null,
        ];
        $meta = array_merge($paginationMeta, is_array($meta) ? $meta : []);
    }

    return jsonResponse(true, $code, $message, $data, $meta);
}

/**
 * Returns error response
 */
function errorResponse($message = 'Error', $errors = null, $code = 500): JsonResponse
{
    // Handle case where code is passed as second parameter (backwards compatibility)
    if (is_int($errors)) {
        $code = $errors;
        $errors = null;
    }

    return jsonResponse(false, $code, $message, null, null, $errors);
}

/**
 * Returns validation error response
 */
function validationErrorResponse($errors, $extraData = [], $message = 'Validation failed', $code = 422): JsonResponse
{
    // If second parameter is a string, it's the old signature (message)
    if (is_string($extraData)) {
        $message = $extraData;
        $extraData = [];
    }

    return jsonResponse(false, $code, $message, $extraData ?: null, null, $errors);
}

/**
 * Returns unauthorized error response
 */
function unauthorizedResponse($message = 'Unauthorized', $code = 401): JsonResponse
{
    return jsonResponse(false, $code, $message);
}

/**
 * Returns forbidden error response
 */
function forbiddenResponse($message = 'Forbidden', $code = 403): JsonResponse
{
    return jsonResponse(false, $code, $message);
}

/**
 * Returns not found error response
 */
function notFoundResponse($message = 'Resource not found', $code = 404): JsonResponse
{
    return jsonResponse(false, $code, $message);
}

/**
 * Returns server error response
 */
function serverErrorResponse($message = 'Internal server error', $code = 500): JsonResponse
{
    return jsonResponse(false, $code, $message);
}

/**
 * Returns response for route not found
 */
function routeNotFoundResponse($route = null, $code = 404): JsonResponse
{
    $message = $route ? "Route [{$route}] not found" : 'Route not found';

    return jsonResponse(false, $code, $message);
}

/**
 * Returns response for method not allowed
 */
function methodNotAllowedResponse($method = null, $code = 405): JsonResponse
{
    $message = $method ? "Method [{$method}] not allowed" : 'Method not allowed';

    return jsonResponse(false, $code, $message);
}

/**
 * Returns response for too many requests
 */
function tooManyRequestsResponse($message = 'Too many requests', $code = 429): JsonResponse
{
    return jsonResponse(false, $code, $message);
}

/**
 * Handle exception and return appropriate JSON response
 */
function handleExceptionResponse(\Throwable $exception): JsonResponse
{
    // Route not found exception
    if ($exception instanceof \Symfony\Component\Routing\Exception\RouteNotFoundException) {
        preg_match('/Route \[(.*?)\]/', $exception->getMessage(), $matches);
        $route = $matches[1] ?? null;

        return routeNotFoundResponse($route);
    }

    // Method not allowed exception
    if ($exception instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
        return methodNotAllowedResponse();
    }

    // Not found exception
    if ($exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        return notFoundResponse();
    }

    // Unauthorized exception
    if ($exception instanceof \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException ||
        $exception instanceof \Illuminate\Auth\AuthenticationException) {
        return unauthorizedResponse();
    }

    // Forbidden exception
    if ($exception instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException ||
        $exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
        return forbiddenResponse();
    }

    // Validation exception
    if ($exception instanceof \Illuminate\Validation\ValidationException) {
        return validationErrorResponse($exception->errors(), 'Validation failed');
    }

    // Too many requests exception
    if ($exception instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
        return tooManyRequestsResponse();
    }

    // Token mismatch exception
    if ($exception instanceof \Illuminate\Session\TokenMismatchException) {
        return errorResponse('Token mismatch', 419);
    }

    // Database exceptions
    if ($exception instanceof \Illuminate\Database\QueryException) {
        if (app()->environment('production')) {
            return serverErrorResponse('Database error occurred');
        }

        return serverErrorResponse($exception->getMessage());
    }

    // Model not found exception
    if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
        return notFoundResponse('Resource not found');
    }

    // Bad request exception
    if ($exception instanceof \Symfony\Component\HttpKernel\Exception\BadRequestHttpException) {
        return errorResponse($exception->getMessage() ?: 'Bad request. Please check your request format.', 400);
    }

    // General server error
    if (app()->environment('production')) {
        return serverErrorResponse();
    }

    return serverErrorResponse($exception->getMessage());
}

/**
 * Get zone ID from request header or authenticated client's default address
 *
 * @param  \Illuminate\Http\Request  $request
 */
function getZoneIdFromRequest($request): ?int
{
    $user = $request->user();

    $zoneId = $request->header('zone-id')
           ?? $request->header('Zone-Id')
           ?? $request->header('zone_id')
           ?? $request->header('Zone_Id')
           ?? $request->header('X-Zone-Id')
           ?? $request->header('ZONE-ID')
           ?? $request->header('ZONE_ID')
           ?? $request->header('X-Zone-ID');

    if ($zoneId !== null && $zoneId !== '') {
        return (int) $zoneId;
    }

    $zoneId = $request->input('zone_id')
           ?? $request->query('zone_id')
           ?? $request->input('zone-id')
           ?? $request->query('zone-id');

    if ($zoneId !== null && $zoneId !== '') {
        return (int) $zoneId;
    }

    // Priority 3: Get from authenticated client's default address (cached)
    if ($user && ! ($user instanceof \Modules\Driver\Models\Driver)) {
        $defaultAddress = getCachedClientDefaultAddress($user);
        if ($defaultAddress && $defaultAddress->zone_id) {
            return (int) $defaultAddress->zone_id;
        }
    }

    return null;
}

/**
 * Get address ID from request headers.
 *
 * Supports "addresse_id" (legacy typo) and "address_id".
 *
 * @param  \Illuminate\Http\Request  $request
 */
function getAddressIdFromRequest($request): ?int
{
    $addressId = $request->header('addresse-id')
        ?? $request->header('Addresse-Id')
        ?? $request->header('addresse_id')
        ?? $request->header('Addresse_Id')
        ?? $request->header('X-Addresse-Id')
        ?? $request->header('X-Addresse-ID')
        ?? $request->header('address-id')
        ?? $request->header('Address-Id')
        ?? $request->header('address_id')
        ?? $request->header('Address_Id')
        ?? $request->header('X-Address-Id')
        ?? $request->header('X-Address-ID')
        ?? $request->header('ADDRESS-ID')
        ?? $request->header('ADDRESS_ID');

    if ($addressId === null || $addressId === '') {
        return null;
    }

    return (int) $addressId;
}

/**
 * Cache and return client's default address.
 *
 * @param  mixed  $user
 * @return \Modules\Address\Models\Address|null
 */
function getCachedClientDefaultAddress($user, int $ttlSeconds = 300)
{
    if (! $user || ! $user->id || $user instanceof \Modules\Driver\Models\Driver) {
        return null;
    }

    $cacheKey = 'client_default_address:'.$user->id;

    return Cache::remember($cacheKey, $ttlSeconds, function () use ($user) {
        return $user->addresses()
            ->where('is_default', true)
            ->first();
    });
}

/**
 * Cache and return one client address by ID.
 *
 * @param  mixed  $user
 * @return \Modules\Address\Models\Address|null
 */
function getCachedClientAddressById($user, int $addressId, int $ttlSeconds = 300)
{
    if (! $user || ! $user->id || $addressId <= 0 || $user instanceof \Modules\Driver\Models\Driver) {
        return null;
    }

    $cacheKey = 'client_address:'.$user->id.':'.$addressId;

    return Cache::remember($cacheKey, $ttlSeconds, function () use ($user, $addressId) {
        return $user->addresses()->whereKey($addressId)->first();
    });
}

/**
 * Resolve branch filters (address, zone, coordinates) from request for client APIs.
 *
 * @param  \Illuminate\Http\Request  $request
 * @return array{zone_id:int|null,address_id:int|null,latitude:float|null,longitude:float|null,address:mixed}
 */
function resolveClientBranchFiltersFromRequest($request): array
{
    $user = $request->user();
    $zoneId = getZoneIdFromRequest($request);
    $addressId = getAddressIdFromRequest($request);
    $address = null;
    $latitude = null;
    $longitude = null;

    if ($addressId && $addressId > 0) {
        $address = getCachedClientAddressById($user, (int) $addressId);

        // Fallback for unauthenticated users accessing public APIs (like laundries/all)
        if (! $address && ! $user) {
            $address = \Modules\Address\Models\Address::find($addressId);
        }

        if ($address && $address->latitude !== null && $address->longitude !== null) {
            $latitude = (float) $address->latitude;
            $longitude = (float) $address->longitude;
            $zoneId = $address->zone_id ?: $zoneId;
        }
    }

    if ($latitude === null && $longitude === null && $request->has('latitude') && $request->has('longitude')) {
        $lat = (float) $request->input('latitude');
        $lng = (float) $request->input('longitude');

        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            $latitude = $lat;
            $longitude = $lng;
        }
    }

    return [
        'zone_id' => $zoneId ? (int) $zoneId : null,
        'address_id' => $address?->id,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'address' => $address,
    ];
}

/**
 * Translate a message with the current locale
 */
function translateMessage(string $key, array $replace = []): string
{
    return __($key, $replace);
}

/**
 * Normalize phone number to consistent format (remove + and spaces)
 */
function normalizePhone(string $phone): string
{
    // Remove all non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    // Remove + prefix if present
    $phone = ltrim($phone, '+');

    return $phone;
}
/**
 * Get a version-based key suffix for a list of tags.
 * This allows using "tagging" with drivers like 'file' and 'database'.
 */
function getTagVersionKey(array $tags): string
{
    try {
        $versionParts = [];
        foreach ($tags as $tag) {
            $version = Cache::get("tag_version:{$tag}", 1);
            $versionParts[] = "{$tag}_{$version}";
        }

        return implode(':', $versionParts);
    } catch (\Throwable) {
        return 'cache_unavailable';
    }
}

/**
 * Flush cache for specific tags by incrementing their version numbers.
 * Works with any cache driver.
 *
 * @param  array|string  $tags
 */
function flushCacheTags($tags): void
{
    try {
        $tags = is_array($tags) ? $tags : [$tags];
        foreach ($tags as $tag) {
            $key = "tag_version:{$tag}";

            // Seed a missing key to the reader's default (see getTagVersionKey()).
            // Without this, the file/database driver initialises a missing key to 1 on the
            // first increment — the SAME value the reader already assumes — so the very first
            // flush after a clean cache would NOT change the version, leaving the cache stale
            // until `php artisan cache:clear` is run manually.
            if (Cache::get($key) === null) {
                Cache::forever($key, 1);
            }

            try {
                Cache::increment($key);
            } catch (\Throwable $e) {
                // Driver without atomic increment support — bump the version manually.
                Cache::forever($key, ((int) Cache::get($key, 1)) + 1);
            }
        }
    } catch (\Throwable) {
        // Cache storage unavailable — skip invalidation; API flows must continue.
    }
}
