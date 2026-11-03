<?php

namespace Modules\Vendor\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

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

    /**
     * Validator with human field names so messages read well ("حقل الاسم مطلوب").
     * Messages follow the request locale (Accept-Language, default ar).
     */
    protected function makeValidator(array $data, array $rules): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, $rules, [], $this->attributeNames());
    }

    /** Pick the string for the current request locale. */
    protected function t(string $ar, string $en): string
    {
        return app()->getLocale() === 'en' ? $en : $ar;
    }

    protected function attributeNames(): array
    {
        $ar = app()->getLocale() !== 'en';
        $names = [
            'name' => ['الاسم', 'name'],
            'name.ar' => ['الاسم بالعربية', 'Arabic name'],
            'name.en' => ['الاسم بالإنجليزية', 'English name'],
            'email' => ['البريد الإلكتروني', 'email'],
            'phone_number' => ['رقم الهاتف', 'phone number'],
            'id_number' => ['رقم الهوية', 'ID number'],
            'branch_id' => ['الفرع', 'branch'],
            'image' => ['الصورة', 'image'],
            'image_document' => ['صورة الهوية', 'ID document'],
            'icon_id' => ['الأيقونة', 'icon'],
            'price' => ['السعر', 'price'],
            'service_ids' => ['الخدمات', 'services'],
            'service_ids.*' => ['الخدمة', 'service'],
            'file' => ['الملف', 'file'],
            'bank_account_id' => ['الحساب البنكي', 'bank account'],
            'amount' => ['المبلغ', 'amount'],
            'status' => ['الحالة', 'status'],
            'search' => ['البحث', 'search'],
            'date_from' => ['من تاريخ', 'date from'],
            'date_to' => ['إلى تاريخ', 'date to'],
            'sort_by' => ['الترتيب حسب', 'sort by'],
            'sort_dir' => ['اتجاه الترتيب', 'sort direction'],
            'year' => ['السنة', 'year'],
            'page' => ['الصفحة', 'page'],
            'per_page' => ['عدد العناصر', 'per page'],
            'type' => ['النوع', 'type'],
            'is_active' => ['الحالة', 'status'],
        ];

        return array_map(fn ($v) => $ar ? $v[0] : $v[1], $names);
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
