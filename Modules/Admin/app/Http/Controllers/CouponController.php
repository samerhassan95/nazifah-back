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
        $data = $request->validated();

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

        $coupon->update($request->validated());

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
