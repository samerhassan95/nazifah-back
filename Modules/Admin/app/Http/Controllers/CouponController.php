<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Discount\Http\Requests\StoreDiscountRequest;
use Modules\Discount\Http\Requests\UpdateDiscountRequest;
use Modules\Discount\Http\Resources\DiscountResource;
use Modules\Discount\Models\Discount;

class CouponController extends Controller
{
    /**
     * Get all coupons with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = Discount::with(['vendors', 'zones', 'clients']);

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by type (percentage/fixed)
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by discount_type
        if ($request->has('discount_type')) {
            $query->where('discount_type', $request->discount_type);
        }
        if ($request->filled('promotion_domain')) {
            $query->where('promotion_domain', $request->promotion_domain);
        }
        if ($request->filled('promotion_kind')) {
            $query->where('promotion_kind', $request->promotion_kind);
        }
        if ($request->has('is_automatic')) {
            $query->where('is_automatic', $request->boolean('is_automatic'));
        }

        // Filter by status (active, expired, scheduled)
        if ($request->has('status')) {
            $now = now();
            switch ($request->status) {
                case 'active':
                    $query->active();
                    break;
                case 'expired':
                    $query->expired();
                    break;
                case 'scheduled':
                    $query->where('start_date', '>', $now);
                    break;
                case 'used_up':
                    $query->whereNotNull('usage_limit')
                        ->whereRaw('used_count >= usage_limit');
                    break;
            }
        }

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhereRaw("JSON_EXTRACT(name, '$.ar') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("JSON_EXTRACT(name, '$.en') LIKE ?", ["%{$search}%"]);
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $coupons = $query->paginate($request->per_page ?? 15);

        return successResponse(
            DiscountResource::collection($coupons),
            'Coupons retrieved successfully'
        );
    }

    /**
     * Create new coupon
     */
    public function store(StoreDiscountRequest $request): JsonResponse
    {
        $data = $this->normalizeDiscountPayload($request->validated());

        // Generate unique code if not provided
        if (empty($data['code'])) {
            $data['code'] = $this->generateUniqueCouponCode();
        }

        $coupon = Discount::create($data);

        // Attach relationships based on discount_type
        if ($request->discount_type === 'vendors' && $request->has('vendor_ids')) {
            $coupon->vendors()->attach($request->vendor_ids);
        } elseif ($request->discount_type === 'zone' && $request->has('zone_ids')) {
            $coupon->zones()->attach($request->zone_ids);
        } elseif ($request->discount_type === 'client' && $request->has('client_ids')) {
            $coupon->clients()->attach($request->client_ids);
        }

        // Cache invalidation is handled by model observers/booted method in Discount model

        return successResponse(
            new DiscountResource($coupon->load(['vendors', 'zones', 'clients'])),
            'Coupon created successfully',
            201
        );
    }

    /**
     * Get coupon details
     */
    public function show(int $id): JsonResponse
    {
        $coupon = Discount::with(['vendors', 'zones', 'clients'])->find($id);

        if (! $coupon) {
            return notFoundResponse('Coupon not found');
        }

        return successResponse(
            new DiscountResource($coupon),
            'Coupon retrieved successfully'
        );
    }

    /**
     * Update coupon
     */
    public function update(UpdateDiscountRequest $request, int $id): JsonResponse
    {
        $coupon = Discount::find($id);

        if (! $coupon) {
            return notFoundResponse('Coupon not found');
        }

        $coupon->update($this->normalizeDiscountPayload($request->validated(), true));

        // Sync relationships if discount_type is updated or relevant IDs are provided
        if ($request->has('vendor_ids') || ($request->discount_type === 'vendors')) {
            $coupon->vendors()->sync($request->vendor_ids ?? []);
        }
        if ($request->has('zone_ids') || ($request->discount_type === 'zone')) {
            $coupon->zones()->sync($request->zone_ids ?? []);
        }
        if ($request->has('client_ids') || ($request->discount_type === 'client')) {
            $coupon->clients()->sync($request->client_ids ?? []);
        }

        return successResponse(
            new DiscountResource($coupon->fresh()->load(['vendors', 'zones', 'clients'])),
            'Coupon updated successfully'
        );
    }

    /**
     * Delete coupon
     */
    public function destroy(int $id): JsonResponse
    {
        $coupon = Discount::find($id);

        if (! $coupon) {
            return notFoundResponse('Coupon not found');
        }

        $coupon->delete();

        return successResponse(null, 'Coupon deleted successfully');
    }

    /**
     * Activate coupon
     */
    public function activate(int $id): JsonResponse
    {
        $coupon = Discount::find($id);

        if (! $coupon) {
            return notFoundResponse('Coupon not found');
        }

        $coupon->update(['is_active' => true]);

        return successResponse(
            new DiscountResource($coupon),
            'Coupon activated successfully'
        );
    }

    /**
     * Deactivate coupon
     */
    public function deactivate(int $id): JsonResponse
    {
        $coupon = Discount::find($id);

        if (! $coupon) {
            return notFoundResponse('Coupon not found');
        }

        $coupon->update(['is_active' => false]);

        return successResponse(
            new DiscountResource($coupon),
            'Coupon deactivated successfully'
        );
    }

    /**
     * Get coupon statistics
     */
    public function statistics(): JsonResponse
    {
        $now = now();

        $stats = [
            'total_coupons' => Discount::count(),
            'active_coupons' => Discount::active()->count(),
            'expired_coupons' => Discount::expired()->count(),
            'scheduled_coupons' => Discount::where('start_date', '>', $now)->count(),
            'used_up_coupons' => Discount::whereNotNull('usage_limit')
                ->whereRaw('used_count >= usage_limit')
                ->count(),
            'total_usage' => Discount::sum('used_count'),
            'by_type' => [
                'percentage' => Discount::where('type', 'percentage')->count(),
                'fixed' => Discount::where('type', 'fixed')->count(),
            ],
            'by_discount_type' => [
                'delivery_free' => Discount::where('discount_type', 'delivery_free')->count(),
                'vendors' => Discount::where('discount_type', 'vendors')->count(),
                'zone' => Discount::where('discount_type', 'zone')->count(),
                'client' => Discount::where('discount_type', 'client')->count(),
                'global' => Discount::where('discount_type', 'global')->count(),
            ],
        ];

        return successResponse($stats, 'Coupon statistics retrieved successfully');
    }

    /**
     * Bulk update coupons status
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'coupon_ids' => ['required', 'array'],
            'coupon_ids.*' => ['required', 'integer', 'exists:discounts,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        Discount::whereIn('id', $request->coupon_ids)
            ->update(['is_active' => $request->is_active]);

        // Invalidate cache
        flushCacheTags(['discounts']);

        return successResponse(
            null,
            'Coupons status updated successfully'
        );
    }

    /**
     * Toggle coupon active status
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $coupon = Discount::find($id);

        if (! $coupon) {
            return notFoundResponse('Coupon not found');
        }

        $coupon->is_active = ! $coupon->is_active;
        $coupon->save();

        return successResponse(
            new DiscountResource($coupon),
            'Coupon status updated successfully'
        );
    }

    /**
     * Generate unique coupon code
     */
    private function generateUniqueCouponCode(): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        } while (Discount::where('code', $code)->exists());

        return $code;
    }

    /**
     * Normalize both legacy dashboard payloads and the new promotion-engine payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeDiscountPayload(array $data, bool $isUpdate = false): array
    {
        if (! isset($data['code']) && isset($data['discount_code'])) {
            $data['code'] = $data['discount_code'];
        }

        if (! isset($data['name']) && isset($data['discount_title'])) {
            $title = (string) $data['discount_title'];
            $data['name'] = ['ar' => $title, 'en' => $title];
        }

        if (! isset($data['type']) && isset($data['discount_type']) && in_array($data['discount_type'], ['fixed', 'percentage'], true)) {
            $data['type'] = $data['discount_type'];
            $data['discount_type'] = match ((string) ($data['target_category'] ?? 'all_clients')) {
                'new_clients' => 'client',
                default => 'global',
            };
            if (($data['target_category'] ?? null) === 'current_clients') {
                $data['segment_filters'] = array_merge((array) ($data['segment_filters'] ?? []), ['min_orders' => 1]);
            }
            if (($data['target_category'] ?? null) === 'new_clients') {
                $data['first_order_only'] = true;
            }
        }

        if (! isset($data['value']) && isset($data['discount_amount'])) {
            $data['value'] = $data['discount_amount'];
        }

        $data['promotion_domain'] = $data['promotion_domain'] ?? Discount::DOMAIN_ORDER;
        $data['promotion_kind'] = $data['promotion_kind']
            ?? (($data['promotion_domain'] ?? Discount::DOMAIN_ORDER) === Discount::DOMAIN_WALLET_TOPUP
                ? Discount::KIND_WALLET_TOPUP_BONUS
                : ($data['applies_to_delivery'] ?? false ? Discount::KIND_DELIVERY_DISCOUNT : Discount::KIND_ORDER_TOTAL_THRESHOLD));
        $data['usage_condition'] = $data['usage_condition'] ?? Discount::USAGE_CONDITION_ALL;
        $data['application_scope'] = $data['application_scope'] ?? Discount::APPLICATION_SCOPE_ORDER_TOTAL;
        $data['is_automatic'] = (bool) ($data['is_automatic'] ?? false);
        $data['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        $data['priority'] = (int) ($data['priority'] ?? 100);
        $data['funding_source'] = $data['funding_source'] ?? 'platform';

        foreach ([
            'usage_service_ids',
            'discount_service_ids',
            'category_ids',
            'vendor_ids',
            'zone_ids',
            'client_ids',
            'branch_ids',
            'city_names',
            'active_days_of_week',
            'required_piece_ids',
            'bundle_rules',
        ] as $listField) {
            if (isset($data[$listField]) && ! is_array($data[$listField])) {
                $data[$listField] = [$data[$listField]];
            }
        }

        if (empty($data['code']) && (! $isUpdate || array_key_exists('code', $data))) {
            $data['code'] = $this->generateUniqueCouponCode();
        }

        return $data;
    }

    /**
     * Duplicate coupon
     */
    public function duplicate(int $id): JsonResponse
    {
        $coupon = Discount::find($id);

        if (! $coupon) {
            return notFoundResponse('Coupon not found');
        }

        $newCoupon = $coupon->replicate();
        $newCoupon->code = $this->generateUniqueCouponCode();
        $newCoupon->used_count = 0;
        $newCoupon->is_active = false;
        $newCoupon->save();

        // Duplicate relationships
        if ($coupon->discount_type === 'vendors') {
            $newCoupon->vendors()->attach($coupon->vendors()->pluck('model_id')->toArray());
        } elseif ($coupon->discount_type === 'zone') {
            $newCoupon->zones()->attach($coupon->zones()->pluck('model_id')->toArray());
        } elseif ($coupon->discount_type === 'client') {
            $newCoupon->clients()->attach($coupon->clients()->pluck('model_id')->toArray());
        }

        return successResponse(
            new DiscountResource($newCoupon->load(['vendors', 'zones', 'clients'])),
            'Coupon duplicated successfully',
            201
        );
    }
}
