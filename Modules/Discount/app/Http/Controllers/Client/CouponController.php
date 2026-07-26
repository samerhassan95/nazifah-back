<?php

namespace Modules\Discount\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Discount\Models\Discount;

class CouponController extends Controller
{
    /**
     * Get all available coupons for clients
     */
    public function index(Request $request): JsonResponse
    {
        $now = now();

        $coupons = Discount::where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereRaw('used_count < usage_limit');
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        $coupons->getCollection()->transform(function ($coupon) {
            return [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'description' => $coupon->description,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
                'min_order_amount' => (float) $coupon->min_order_amount,
                'max_discount_amount' => $coupon->max_discount_amount ? (float) $coupon->max_discount_amount : null,
                'end_date' => $coupon->end_date->toISOString(),
                'usage_remaining' => $coupon->usage_limit ? ($coupon->usage_limit - $coupon->used_count) : null,
            ];
        });

        return successResponse(
            $coupons,
            'Available coupons retrieved successfully'
        );
    }

    /**
     * Validate coupon code
     */
    public function validate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
            'order_amount' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $now = now();
        $coupon = Discount::where('code', $request->code)
            ->where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->first();

        if (! $coupon) {
            return errorResponse('Invalid or expired coupon code', 400);
        }

        // Check usage limit
        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            return errorResponse('Coupon usage limit reached', 400);
        }

        // Check minimum order amount
        if ($request->order_amount < $coupon->min_order_amount) {
            return errorResponse(
                "Minimum order amount is {$coupon->min_order_amount} SAR",
                400
            );
        }

        // Calculate discount amount
        $discountAmount = 0;
        if ($coupon->type === Discount::TYPE_PERCENTAGE) {
            $discountAmount = $request->order_amount * ($coupon->value / 100);
            if ($coupon->max_discount_amount) {
                $discountAmount = min($discountAmount, $coupon->max_discount_amount);
            }
        } else {
            $discountAmount = $coupon->value;
        }

        return successResponse([
            'valid' => true,
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
            ],
            'discount_amount' => (float) $discountAmount,
            'final_amount' => (float) ($request->order_amount - $discountAmount),
        ], 'Coupon is valid');
    }

    /**
     * Get coupon by code
     */
    public function getByCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $now = now();
        $coupon = Discount::where('code', $request->code)
            ->where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->first();

        if (! $coupon) {
            return errorResponse('Coupon not found or not available', 404);
        }

        // Check usage limit
        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            return errorResponse('Coupon usage limit reached', 400);
        }

        return successResponse([
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'description' => $coupon->description,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'min_order_amount' => (float) $coupon->min_order_amount,
            'max_discount_amount' => $coupon->max_discount_amount ? (float) $coupon->max_discount_amount : null,
            'end_date' => $coupon->end_date->toISOString(),
            'usage_remaining' => $coupon->usage_limit ? ($coupon->usage_limit - $coupon->used_count) : null,
        ], 'Coupon retrieved successfully');
    }

    /**
     * Apply coupon to order (calculate discount)
     */
    public function apply(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
            'order_amount' => ['required', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $now = now();
        $coupon = Discount::where('code', $request->code)
            ->where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->first();

        if (! $coupon) {
            return errorResponse('Invalid or expired coupon code', 400);
        }

        // Check usage limit
        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            return errorResponse('Coupon usage limit reached', 400);
        }

        // Check minimum order amount
        if ($request->order_amount < $coupon->min_order_amount) {
            return errorResponse(
                "Minimum order amount is {$coupon->min_order_amount} SAR",
                400
            );
        }

        // Calculate discount amount
        $discountAmount = 0;
        if ($coupon->type === Discount::TYPE_PERCENTAGE) {
            $discountAmount = $request->order_amount * ($coupon->value / 100);
            if ($coupon->max_discount_amount) {
                $discountAmount = min($discountAmount, $coupon->max_discount_amount);
            }
        } else {
            $discountAmount = min($coupon->value, $request->order_amount);
        }

        $finalAmount = $request->order_amount - $discountAmount;

        return successResponse([
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
            ],
            'order_amount' => (float) $request->order_amount,
            'discount_amount' => (float) $discountAmount,
            'final_amount' => (float) $finalAmount,
            'savings' => (float) $discountAmount,
            'savings_percentage' => $request->order_amount > 0
                ? round(($discountAmount / $request->order_amount) * 100, 2)
                : 0,
        ], 'Coupon applied successfully');
    }
}
