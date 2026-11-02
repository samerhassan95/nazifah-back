<?php

namespace Modules\Vendor\Support;

use Illuminate\Http\JsonResponse;

/**
 * Response helpers for the Laundry Dashboard → Operations Management API.
 *
 * Envelope (success): { status, code, message: {ar, en}, data, meta? }
 * Envelope (failure): { status:false, code, message: {ar, en}, errors? }
 */
trait LaundryDashboardResponse
{
    protected function ok(mixed $data, string $ar, string $en, int $code = 200, ?array $meta = null): JsonResponse
    {
        $body = [
            'status' => true,
            'code' => $code,
            'message' => ['ar' => $ar, 'en' => $en],
            'data' => $data,
        ];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $code);
    }

    protected function fail(string $ar, string $en, int $code = 400, mixed $errors = null): JsonResponse
    {
        $body = [
            'status' => false,
            'code' => $code,
            'message' => ['ar' => $ar, 'en' => $en],
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $code);
    }

    protected function invalid(mixed $errors): JsonResponse
    {
        return $this->fail('بيانات غير صالحة', 'Validation failed', 422, $errors);
    }

    protected function notFound(string $ar, string $en): JsonResponse
    {
        return $this->fail($ar, $en, 404);
    }

    protected function vendorId(): int
    {
        return (int) request()->user()->vendor_id;
    }

    /**
     * Normalise a translatable value to {ar, en}.
     */
    protected function bilingual(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ['ar' => $value, 'en' => $value];
        }

        $value = (array) $value;

        return [
            'ar' => (string) ($value['ar'] ?? $value['en'] ?? ''),
            'en' => (string) ($value['en'] ?? $value['ar'] ?? ''),
        ];
    }

    protected function paginationMeta($paginator): array
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
}
