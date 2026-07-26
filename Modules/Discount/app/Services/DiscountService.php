<?php

namespace Modules\Discount\Services;

use App\Services\OrderCatalogAvailabilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Address\Models\Address;
use Modules\Discount\Interfaces\DiscountRepositoryInterface;
use Modules\Discount\Models\Discount;
use Modules\Order\Support\OrderItemsNormalizer;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\ServiceAddition;

class DiscountService
{
    public function __construct(
        private DiscountRepositoryInterface $discountRepository,
        private OrderCatalogAvailabilityService $catalogAvailabilityService
    ) {}

    public function getAllDiscounts(array $filters = []): LengthAwarePaginator
    {
        return $this->discountRepository->all($filters);
    }

    public function getDiscountById(int $id): ?Discount
    {
        return $this->discountRepository->find($id);
    }

    public function createDiscount(array $data): Discount
    {
        return $this->discountRepository->create($data);
    }

    public function updateDiscount(int $id, array $data): ?Discount
    {
        $discount = $this->discountRepository->find($id);

        if (! $discount) {
            return null;
        }

        $this->discountRepository->update($discount, $data);

        return $discount->fresh();
    }

    public function deleteDiscount(int $id): bool
    {
        $discount = $this->discountRepository->find($id);

        if (! $discount) {
            return false;
        }

        return $this->discountRepository->delete($discount);
    }

    /**
     * Validate coupon and calculate discount
     *
     * @param  array  $items  - [['piece_id' => int, 'quantity' => int, 'service_id' => int, 'additional_service_ids' => int[]?], ...] (piece_id or item_type_id)
     * @param  int|null  $userId  - Current user ID for client-specific discounts
     * @param  int|null  $vendorId  - Vendor ID for vendor-specific discounts
     * @param  string  $lang  - Language for error messages (ar or en)
     * @param  int|null  $branchId  - Branch ID for branch-aware pricing (when provided, order amount uses branch prices)
     * @return array - ['success' => bool, 'message' => string, 'data' => [...], 'code' => int]
     */
    public function validateAndCalculateDiscount(
        string $couponCode,
        array $items,
        ?int $userId = null,
        ?int $vendorId = null,
        string $lang = 'ar',
        ?int $branchId = null
    ): array {
        // Localized messages
        $msg = $this->getMessages();

        // Validate items and calculate order amount
        $itemsValidation = $this->validateItems($items, $vendorId, $lang, $branchId);
        if (! $itemsValidation['success']) {
            return $itemsValidation;
        }

        $orderAmount = $itemsValidation['data']['order_amount'];
        $calculatedVendorId = $itemsValidation['data']['vendor_id'];

        // Find and validate discount
        $discount = Discount::with(['vendors', 'zones', 'clients'])
            ->where('code', $couponCode)
            ->where('is_active', true)
            ->first();

        if (! $discount) {
            return [
                'success' => false,
                'message' => $msg['invalid_coupon'][$lang],
                'code' => 400,
            ];
        }

        // Check date validity
        if ($discount->start_date && $discount->start_date > now()) {
            return [
                'success' => false,
                'message' => $msg['not_yet_active'][$lang],
                'code' => 400,
            ];
        }

        if ($discount->end_date && $discount->end_date < now()) {
            return [
                'success' => false,
                'message' => $msg['expired'][$lang],
                'code' => 400,
            ];
        }

        // Check usage limit
        if ($discount->usage_limit && $discount->used_count >= $discount->usage_limit) {
            return [
                'success' => false,
                'message' => $msg['usage_limit'][$lang],
                'code' => 400,
            ];
        }

        // Check minimum order amount
        if ($discount->min_order_amount && $orderAmount < $discount->min_order_amount) {
            $minMsg = $msg['min_order'][$lang].' '.number_format($discount->min_order_amount, 2).' SAR';

            return [
                'success' => false,
                'message' => $minMsg,
                'code' => 400,
                'errors' => [
                    'order_amount' => (float) round($orderAmount, 2),
                    'min_order_amount' => (float) $discount->min_order_amount,
                    'amount_needed' => (float) round(max(0, $discount->min_order_amount - $orderAmount), 2),
                ],
            ];
        }

        // Validate discount type eligibility
        $eligibilityCheck = $this->checkDiscountEligibility(
            $discount,
            $userId,
            $calculatedVendorId,
            $lang
        );

        if (! $eligibilityCheck['success']) {
            return $eligibilityCheck;
        }

        // Calculate discount amount
        $discountAmount = $this->calculateDiscountAmount($discount, $orderAmount);

        return [
            'success' => true,
            'message' => $msg['coupon_valid'][$lang],
            'code' => 200,
            'data' => [
                'discount' => $discount,
                'discount_amount' => $discountAmount,
                'order_amount' => $orderAmount,
                'items_subtotal_after_discount' => round($orderAmount - $discountAmount, 2),
                'final_amount' => round($orderAmount - $discountAmount, 2),
                'vendor_id' => $calculatedVendorId,
                'pieces' => $itemsValidation['data']['pieces'],
            ],
        ];
    }

    /**
     * Validate items belong to same vendor and calculate order amount.
     * Items may use piece_id (API) or item_type_id (legacy). When branchId is set, uses branch-aware pricing.
     */
    private function validateItems(array $items, ?int $expectedVendorId, string $lang, ?int $branchId = null): array
    {
        $msg = $this->getMessages();

        $pieceIds = collect($items)->map(function ($item) {
            return $item['piece_id'] ?? $item['item_type_id'] ?? null;
        })->filter()->unique()->values()->all();

        $pieces = Piece::with(['vendor', 'services'])
            ->whereIn('id', $pieceIds)
            ->get();

        if ($pieces->count() !== count($pieceIds)) {
            return [
                'success' => false,
                'message' => $msg['items_unavailable'][$lang],
                'code' => 400,
            ];
        }

        // Validate all items belong to same vendor
        $vendorIds = $pieces->pluck('vendor_id')->unique();
        if ($vendorIds->count() > 1) {
            return [
                'success' => false,
                'message' => $msg['not_same_vendor'][$lang],
                'code' => 400,
                'errors' => [
                    'items_vendor_ids' => $vendorIds->values()->all(),
                ],
            ];
        }

        $vendorId = (int) $vendorIds->first();

        // If expected vendor ID provided, validate match
        if ($expectedVendorId && $vendorId != $expectedVendorId) {
            $errorMsg = $lang === 'ar'
                ? 'العناصر لا تنتمي للبائع المحدد'
                : 'Items vendor does not match selected laundry (branch_id)';

            return [
                'success' => false,
                'message' => $errorMsg,
                'code' => 400,
                'errors' => [
                    'expected_vendor_id' => $expectedVendorId,
                    'items_vendor_id' => $vendorId,
                ],
            ];
        }

        // Calculate order amount (branch-aware when branchId provided)
        $orderAmount = 0;
        $itemsBreakdown = [];
        foreach ($items as $item) {
            $pieceId = $item['piece_id'] ?? $item['item_type_id'] ?? null;
            if ($pieceId === null) {
                return [
                    'success' => false,
                    'message' => $msg['items_unavailable'][$lang],
                    'code' => 400,
                ];
            }

            /** @var \Modules\Piece\Models\Piece $piece */
            $piece = $pieces->firstWhere('id', $pieceId);

            $mainServiceIds = OrderItemsNormalizer::mainServiceIds(
                OrderItemsNormalizer::normalizeOne(is_array($item) ? $item : [])
            );
            if ($mainServiceIds === []) {
                return [
                    'success' => false,
                    'message' => $msg['items_unavailable'][$lang],
                    'code' => 400,
                ];
            }

            $servicesTotal = 0.0;
            $primaryServiceId = $mainServiceIds[0];
            foreach ($mainServiceIds as $mainServiceId) {
                if ($branchId !== null) {
                    $availabilityError = $this->catalogAvailabilityService->validateOrderLineForNewOrder(
                        $branchId,
                        (int) $pieceId,
                        (int) $mainServiceId,
                        $item['additional_service_ids'] ?? [],
                        $lang
                    );
                    if ($availabilityError !== null) {
                        return [
                            'success' => false,
                            'message' => $availabilityError,
                            'code' => 400,
                        ];
                    }
                }

                $service = $piece->services->firstWhere('id', $mainServiceId);
                if (! $service) {
                    $pieceName = method_exists($piece, 'getTranslation')
                        ? $piece->getTranslation('name', $lang)
                        : $piece->name;
                    $serviceMsg = $lang === 'ar'
                        ? 'الخدمة غير متاحة للعنصر: '.$pieceName
                        : 'Service not available for item: '.$pieceName;

                    return [
                        'success' => false,
                        'message' => $serviceMsg,
                        'code' => 400,
                    ];
                }

                if ($branchId !== null) {
                    $servicesTotal += (float) $service->getPriceForPieceAtBranch($piece->id, $branchId);
                } else {
                    $servicesTotal += (float) ($service->pivot->price ?? 0);
                }
            }

            $service = $piece->services->firstWhere('id', $primaryServiceId);

            $additionalServicesDetail = [];
            if ($branchId !== null) {
                // New pricing model: piece_price = 0, service prices summed for all main services.
                $piecePrice = 0.0;
                $servicePrice = $servicesTotal;
                $additionalTotal = 0;
                if (! empty($item['additional_service_ids'])) {
                    foreach (array_unique($item['additional_service_ids']) as $additionalServiceId) {
                        $additionModel = ServiceAddition::find($additionalServiceId);
                        if (! $additionModel) {
                            continue;
                        }
                        if ($branchId !== null && ! $this->catalogAvailabilityService->isServiceAdditionActiveForPieceAtBranch(
                            (int) $additionalServiceId,
                            (int) $piece->id,
                            $branchId
                        )) {
                            continue;
                        }
                        if ($additionModel) {
                            $addPrice = $additionModel->getPriceForPieceAtBranch($piece->id, $branchId);
                            $additionalTotal += $addPrice;
                            $additionalServicesDetail[] = [
                                'id' => $additionModel->id,
                                'name' => $additionModel->name,
                                'price' => (float) $addPrice,
                            ];
                        }
                    }
                }
                $unitPrice = $piecePrice + $servicePrice + $additionalTotal;
            } else {
                $piecePrice = 0;
                $servicePrice = $servicesTotal;
                $additionalTotal = 0;
                $unitPrice = $servicePrice;
                if (! empty($item['additional_service_ids'])) {
                    foreach (array_unique($item['additional_service_ids']) as $additionalServiceId) {
                        $additionModel = ServiceAddition::find($additionalServiceId);
                        if (! $additionModel) {
                            continue;
                        }
                        if ($branchId !== null && ! $this->catalogAvailabilityService->isServiceAdditionActiveForPieceAtBranch(
                            (int) $additionalServiceId,
                            (int) $piece->id,
                            $branchId
                        )) {
                            continue;
                        }
                        if ($additionModel) {
                            $addPrice = (float) ($additionModel->price ?? 0);
                            $additionalTotal += $addPrice;
                            $unitPrice += $addPrice;
                            $additionalServicesDetail[] = [
                                'id' => $additionModel->id,
                                'name' => $additionModel->name,
                                'price' => $addPrice,
                            ];
                        }
                    }
                }
            }

            $quantity = (int) $item['quantity'];
            $orderAmount += $unitPrice * $quantity;

            $itemsBreakdown[] = [
                'piece_id' => (int) $pieceId,
                'piece_name' => method_exists($piece, 'getTranslation')
                    ? $piece->getTranslation('name', $lang) : $piece->name,
                'piece_price' => (float) $piecePrice,
                'service_id' => (int) $primaryServiceId,
                'service_ids' => $mainServiceIds,
                'service_price' => (float) $servicePrice,
                'additional_services' => $additionalServicesDetail,
                'unit_price' => (float) $unitPrice,
                'quantity' => $quantity,
                'total_price' => (float) ($unitPrice * $quantity),
            ];
        }

        return [
            'success' => true,
            'data' => [
                'order_amount' => round($orderAmount, 2),
                'vendor_id' => $vendorId,
                'pieces' => $pieces,
                'items_breakdown' => $itemsBreakdown,
            ],
        ];
    }

    /**
     * Check discount eligibility based on discount_type
     */
    private function checkDiscountEligibility(
        Discount $discount,
        ?int $userId,
        int $vendorId,
        string $lang
    ): array {
        $msg = $this->getMessages();

        switch ($discount->discount_type) {
            case Discount::DISCOUNT_TYPE_CLIENT:
                if (! $userId || ! $discount->clients->contains('id', $userId)) {
                    return [
                        'success' => false,
                        'message' => $msg['not_for_client'][$lang],
                        'code' => 403,
                    ];
                }
                break;

            case Discount::DISCOUNT_TYPE_VENDORS:
                if (! $discount->vendors->contains('id', $vendorId)) {
                    return [
                        'success' => false,
                        'message' => $msg['not_for_vendor'][$lang],
                        'code' => 400,
                    ];
                }
                break;

            case Discount::DISCOUNT_TYPE_ZONE:
                if (! $userId) {
                    return [
                        'success' => false,
                        'message' => $msg['zone_unknown'][$lang],
                        'code' => 400,
                    ];
                }

                $userAddress = Address::where('client_id', $userId)
                    ->where('is_default', true)
                    ->orWhere('client_id', $userId)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if (! $userAddress || ! $userAddress->zone_id) {
                    return [
                        'success' => false,
                        'message' => $msg['zone_unknown'][$lang],
                        'code' => 400,
                    ];
                }

                if (! $discount->zones->contains('id', $userAddress->zone_id)) {
                    return [
                        'success' => false,
                        'message' => $msg['not_for_zone'][$lang],
                        'code' => 400,
                    ];
                }
                break;

            case Discount::DISCOUNT_TYPE_DELIVERY_FREE:
            case Discount::DISCOUNT_TYPE_GLOBAL:
                // Available to all users
                break;

            default:
                return [
                    'success' => false,
                    'message' => $msg['invalid_discount_type'][$lang],
                    'code' => 500,
                ];
        }

        return ['success' => true];
    }

    /**
     * Soft-apply a coupon by code: never errors for eligibility.
     * Returns applied=false when the code is missing/invalid or rules are not met.
     *
     * @return array{applied: bool, discount: ?Discount, discount_amount: float}
     */
    public function softApplyCouponCode(
        string $couponCode,
        float $orderAmount,
        ?int $userId = null,
        ?int $vendorId = null
    ): array {
        $discount = Discount::with(['vendors', 'zones', 'clients'])
            ->where('code', $couponCode)
            ->where('is_active', true)
            ->first();

        if (! $discount) {
            return [
                'applied' => false,
                'discount' => null,
                'discount_amount' => 0.0,
            ];
        }

        return $this->applyIfEligible($discount, $orderAmount, $userId, $vendorId, false);
    }

    /**
     * Apply a known discount when all rules pass; otherwise cancel it (no error).
     * When $alreadyAppliedOnOrder is true, usage_limit is skipped (already counted at checkout).
     *
     * @return array{applied: bool, discount: ?Discount, discount_amount: float}
     */
    public function applyIfEligible(
        Discount $discount,
        float $orderAmount,
        ?int $userId = null,
        ?int $vendorId = null,
        bool $alreadyAppliedOnOrder = false
    ): array {
        $none = [
            'applied' => false,
            'discount' => null,
            'discount_amount' => 0.0,
        ];

        if (! $discount->is_active) {
            return $none;
        }

        if ($discount->start_date && $discount->start_date > now()) {
            return $none;
        }

        if ($discount->end_date && $discount->end_date < now()) {
            return $none;
        }

        if (! $alreadyAppliedOnOrder && $discount->usage_limit && $discount->used_count >= $discount->usage_limit) {
            return $none;
        }

        if ($discount->min_order_amount && $orderAmount < (float) $discount->min_order_amount) {
            return $none;
        }

        $eligibility = $this->checkDiscountEligibility($discount, $userId, $vendorId, 'en');
        if (! $eligibility['success']) {
            return $none;
        }

        return [
            'applied' => true,
            'discount' => $discount,
            'discount_amount' => $this->calculateDiscountAmount($discount, $orderAmount),
        ];
    }

    /**
     * Recalculate an already-applied order coupon on a new items subtotal.
     * Skips usage / expiry / eligibility checks — the coupon was accepted at checkout.
     *
     * Prefer applyIfEligible() when min-order / other rules should still cancel the discount.
     */
    public function amountForOrderTotal(Discount $discount, float $orderAmount): float
    {
        return $this->calculateDiscountAmount($discount, $orderAmount);
    }

    /**
     * Calculate discount amount based on type
     */
    public function calculateDiscountAmount(Discount $discount, float $orderAmount): float
    {
        if ($discount->type === Discount::TYPE_PERCENTAGE) {
            $discountAmount = $orderAmount * ($discount->value / 100);
            if ($discount->max_discount_amount) {
                $discountAmount = min($discountAmount, $discount->max_discount_amount);
            }
        } else {
            $discountAmount = min($discount->value, $orderAmount);
        }

        return round($discountAmount, 2);
    }

    /**
     * Increment discount usage count
     */
    public function incrementUsage(Discount $discount): void
    {
        try {
            $discount->increment('used_count');
        } catch (\Exception $e) {
        }
    }

    /**
     * Get localized messages
     */
    private function getMessages(): array
    {
        return [
            'items_unavailable' => [
                'en' => 'Some items are no longer available',
                'ar' => 'بعض العناصر غير متاحة حالياً',
            ],
            'not_same_vendor' => [
                'en' => 'All items must belong to the same vendor',
                'ar' => 'يجب أن تكون جميع العناصر من نفس البائع',
            ],
            'invalid_coupon' => [
                'en' => 'Invalid or expired coupon code',
                'ar' => 'كود القسيمة غير صالح أو منتهي',
            ],
            'not_yet_active' => [
                'en' => 'Coupon is not yet active',
                'ar' => 'القسيمة لم تصبح فعالة بعد',
            ],
            'expired' => [
                'en' => 'Coupon has expired',
                'ar' => 'انتهت صلاحية القسيمة',
            ],
            'usage_limit' => [
                'en' => 'Coupon usage limit reached',
                'ar' => 'تم الوصول إلى حد استخدام القسيمة',
            ],
            'min_order' => [
                'en' => 'Minimum order amount is',
                'ar' => 'الحد الأدنى لمبلغ الطلب هو',
            ],
            'not_for_client' => [
                'en' => 'This coupon is not available for your account',
                'ar' => 'هذه القسيمة غير متاحة لحسابك',
            ],
            'not_for_vendor' => [
                'en' => 'This coupon is not valid for the selected vendor',
                'ar' => 'هذه القسيمة غير صالحة للبائع المحدد',
            ],
            'zone_unknown' => [
                'en' => 'Unable to determine delivery zone for coupon validation',
                'ar' => 'تعذر تحديد منطقة التوصيل للتحقق من القسيمة',
            ],
            'not_for_zone' => [
                'en' => 'This coupon is not valid for your delivery zone',
                'ar' => 'هذه القسيمة غير صالحة لمنطقة التوصيل الخاصة بك',
            ],
            'invalid_discount_type' => [
                'en' => 'Invalid discount type',
                'ar' => 'نوع الخصم غير صالح',
            ],
            'coupon_valid' => [
                'en' => 'Coupon is valid and applicable',
                'ar' => 'القسيمة صالحة ويمكن تطبيقها',
            ],
        ];
    }
}
