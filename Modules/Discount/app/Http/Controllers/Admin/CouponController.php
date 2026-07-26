<?php

namespace Modules\Discount\Http\Controllers\Admin;

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
        $query = Discount::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by status (active, expired, scheduled)
        if ($request->has('status')) {
            $now = now();
            switch ($request->status) {
                case 'active':
                    $query->where('is_active', true)
                        ->where('start_date', '<=', $now)
                        ->where('end_date', '>=', $now);
                    break;
                case 'expired':
                    $query->where('end_date', '<', $now);
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

        $coupons = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

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

        return successResponse(
            new DiscountResource($coupon),
            'Coupon created successfully',
            201
        );
    }

    /**
     * Get coupon details
     */
    public function show(int $id): JsonResponse
    {
        $coupon = Discount::find($id);

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

        return successResponse(
            new DiscountResource($coupon),
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
            'active_coupons' => Discount::where('is_active', true)
                ->where('start_date', '<=', $now)
                ->where('end_date', '>=', $now)
                ->count(),
            'expired_coupons' => Discount::where('end_date', '<', $now)->count(),
            'scheduled_coupons' => Discount::where('start_date', '>', $now)->count(),
            'used_up_coupons' => Discount::whereNotNull('usage_limit')
                ->whereRaw('used_count >= usage_limit')
                ->count(),
            'total_usage' => Discount::sum('used_count'),
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

        return successResponse(
            null,
            'Coupons status updated successfully'
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

        return successResponse(
            new DiscountResource($newCoupon),
            'Coupon duplicated successfully',
            201
        );
    }
}
