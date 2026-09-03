<?php

namespace Modules\Discount\Services;

use App\Services\OrderCatalogAvailabilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Address\Models\Address;
use Modules\Branch\Models\Branch;
use Modules\Client\Models\Client;
use Modules\Discount\Interfaces\DiscountRepositoryInterface;
use Modules\Discount\Models\Discount;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderItemsNormalizer;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
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
     * Validate a manual coupon against order items and return the discount result.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, message: string, code: int, data?: array<string,mixed>, errors?: array<string,mixed>|null}
     */
    public function validateAndCalculateDiscount(
        string $couponCode,
        array $items,
        ?int $userId = null,
        ?int $vendorId = null,
        string $lang = 'ar',
        ?int $branchId = null,
        float $deliveryFee = 0.0,
        ?string $city = null,
        bool $hasDelivery = true
    ): array {
        $msg = $this->getMessages();
        $itemsValidation = $this->validateItems($items, $vendorId, $lang, $branchId);
        if (! $itemsValidation['success']) {
            return $itemsValidation;
        }

        $context = $this->buildOrderContext(
            $itemsValidation['data']['items_breakdown'],
            (float) $itemsValidation['data']['order_amount'],
            (int) $itemsValidation['data']['vendor_id'],
            $userId,
            $branchId,
            $deliveryFee,
            $city,
            $hasDelivery
        );

        $discount = $this->discountQueryForDomain(Discount::DOMAIN_ORDER)
            ->where('code', $couponCode)
            ->first();

        if (! $discount) {
            return [
                'success' => false,
                'message' => $msg['invalid_coupon'][$lang],
                'code' => 400,
            ];
        }

        $evaluation = $this->evaluateOrderDiscount($discount, $context, $lang, false);
        if (! $evaluation['success']) {
            return [
                'success' => false,
                'message' => $evaluation['message'] ?? $msg['invalid_coupon'][$lang],
                'code' => $evaluation['code'] ?? 400,
                'errors' => $evaluation['errors'] ?? null,
            ];
        }

        return [
            'success' => true,
            'message' => $this->appliedSuccessMessage($discount, $lang),
            'code' => 200,
            'data' => [
                'discount' => $discount,
                'discount_amount' => (float) $evaluation['discount_amount'],
                'delivery_discount_amount' => (float) ($evaluation['delivery_discount_amount'] ?? 0.0),
                'discount_base_amount' => round((float) $evaluation['discount_base_amount'], 2),
                'order_amount' => (float) $context['order_amount'],
                'items_breakdown' => $itemsValidation['data']['items_breakdown'],
                'items_subtotal_after_discount' => round((float) $context['order_amount'] - (float) $evaluation['discount_amount'], 2),
                'final_amount' => round(
                    ((float) $context['order_amount'] - (float) $evaluation['discount_amount'])
                    + ((float) $context['delivery_fee'] - (float) ($evaluation['delivery_discount_amount'] ?? 0.0)),
                    2
                ),
                'vendor_id' => (int) $context['vendor_id'],
                'pieces' => $itemsValidation['data']['pieces'],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{applied: bool, discount: ?Discount, discount_amount: float, delivery_discount_amount: float, discount_base_amount: float}
     */
    public function findBestAutomaticOrderDiscount(
        array $items,
        ?int $userId = null,
        ?int $vendorId = null,
        string $lang = 'ar',
        ?int $branchId = null,
        float $deliveryFee = 0.0,
        ?string $city = null
    ): array {
        $itemsValidation = $this->validateItems($items, $vendorId, $lang, $branchId);
        if (! $itemsValidation['success']) {
            return [
                'applied' => false,
                'discount' => null,
                'discount_amount' => 0.0,
                'delivery_discount_amount' => 0.0,
                'discount_base_amount' => 0.0,
            ];
        }

        $context = $this->buildOrderContext(
            $itemsValidation['data']['items_breakdown'],
            (float) $itemsValidation['data']['order_amount'],
            (int) $itemsValidation['data']['vendor_id'],
            $userId,
            $branchId,
            $deliveryFee,
            $city
        );

        return $this->selectBestAutomaticOrderDiscount($context, $lang);
    }

    /**
     * @return array{applied: bool, discount: ?Discount, bonus_amount: float}
     */
    public function findBestWalletTopupDiscount(
        float $topupAmount,
        int $clientId,
        ?int $branchId = null,
        ?string $city = null,
        string $lang = 'ar'
    ): array {
        $context = [
            'topup_amount' => round(max(0, $topupAmount), 2),
            'client_id' => $clientId,
            'branch_id' => $branchId,
            'city' => $city,
            'order_count' => $this->clientOrdersCount($clientId),
            'wallet_topup_count' => $this->clientCompletedTopupCount($clientId),
            'client' => $clientId > 0 ? Client::find($clientId) : null,
        ];

        $best = null;
        foreach ($this->automaticDiscountsForDomain(Discount::DOMAIN_WALLET_TOPUP) as $discount) {
            /** @var Discount $discount */
            $evaluation = $this->evaluateWalletTopupDiscount($discount, $context, $lang);
            if (! $evaluation['success']) {
                continue;
            }

            $bonusAmount = (float) ($evaluation['bonus_amount'] ?? 0.0);
            if ($best === null || $this->isBetterCandidate($discount, $bonusAmount, $best['discount'], $best['bonus_amount'])) {
                $best = [
                    'discount' => $discount,
                    'bonus_amount' => $bonusAmount,
                ];
            }
        }

        if ($best === null) {
            return ['applied' => false, 'discount' => null, 'bonus_amount' => 0.0];
        }

        return [
            'applied' => true,
            'discount' => $best['discount'],
            'bonus_amount' => round((float) $best['bonus_amount'], 2),
        ];
    }

    /**
     * Re-evaluate a known order discount once delivery/city context is available.
     *
     * @param  array<int, array<string, mixed>>  $itemsBreakdown
     * @return array{applied: bool, discount_amount: float, delivery_discount_amount: float, discount_base_amount: float}
     */
    public function evaluateKnownOrderDiscount(
        Discount $discount,
        array $itemsBreakdown,
        float $orderAmount,
        ?int $userId = null,
        ?int $vendorId = null,
        ?int $branchId = null,
        float $deliveryFee = 0.0,
        ?string $city = null,
        bool $alreadyAppliedOnOrder = false,
        string $lang = 'ar'
    ): array {
        $context = $this->buildOrderContext(
            $itemsBreakdown,
            $orderAmount,
            (int) ($vendorId ?? 0),
            $userId,
            $branchId,
            $deliveryFee,
            $city
        );
        $evaluation = $this->evaluateOrderDiscount($discount, $context, $lang, $alreadyAppliedOnOrder);

        return [
            'applied' => (bool) ($evaluation['success'] ?? false),
            'discount_amount' => (float) ($evaluation['discount_amount'] ?? 0.0),
            'delivery_discount_amount' => (float) ($evaluation['delivery_discount_amount'] ?? 0.0),
            'discount_base_amount' => (float) ($evaluation['discount_base_amount'] ?? 0.0),
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
        string $lang,
        array $context = []
    ): array {
        $msg = $this->getMessages();

        $branchCityCheck = $this->passesBranchAndCityFilters($discount, $context, $lang);
        if (! $branchCityCheck['success']) {
            return $branchCityCheck;
        }

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
     * @param  array<int, array<string, mixed>>  $itemsBreakdown
     * @return array{success: bool, message?: string, code?: int}
     */
    private function validateUsageConditions(Discount $discount, array $itemsBreakdown, string $lang): array
    {
        $usageCondition = (string) ($discount->usage_condition ?? Discount::USAGE_CONDITION_ALL);
        if ($usageCondition !== Discount::USAGE_CONDITION_SERVICES) {
            return ['success' => true];
        }

        $requiredServiceIds = array_values(array_unique(array_map('intval', (array) ($discount->usage_service_ids ?? []))));
        if ($requiredServiceIds === []) {
            return ['success' => true];
        }

        foreach ($itemsBreakdown as $line) {
            $lineServiceIds = array_map('intval', (array) ($line['service_ids'] ?? []));
            if (array_intersect($requiredServiceIds, $lineServiceIds) !== []) {
                return ['success' => true];
            }
        }

        return [
            'success' => false,
            'message' => $lang === 'ar'
                ? 'هذا الكوبون يتطلب وجود خدمات محددة في الطلب.'
                : 'This coupon requires specific services in the order.',
            'code' => 400,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsBreakdown
     */
    private function resolveDiscountBaseAmount(Discount $discount, float $orderAmount, array $itemsBreakdown, float $deliveryFee = 0.0): float
    {
        if ($this->isDeliveryDiscount($discount)) {
            return round(max(0.0, $deliveryFee), 2);
        }

        $serviceIds = array_values(array_unique(array_map('intval', (array) ($discount->discount_service_ids ?? []))));
        $categoryIds = array_values(array_unique(array_map('intval', (array) ($discount->category_ids ?? []))));

        // Item-scoped discounts (services and/or categories) restrict the
        // base amount to matching line items instead of the whole order.
        // Inferred directly from which ID lists are populated, rather than
        // requiring a separate application_scope flag to be set beforehand.
        if ($serviceIds === [] && $categoryIds === []) {
            return $orderAmount;
        }

        $matchServiceIds = $serviceIds;
        if ($categoryIds !== []) {
            $categoryServiceIds = Service::whereIn('category_id', $categoryIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $matchServiceIds = array_values(array_unique(array_merge($matchServiceIds, $categoryServiceIds)));
        }

        if ($matchServiceIds === []) {
            return $orderAmount;
        }

        $itemsSubtotal = 0.0;
        foreach ($itemsBreakdown as $line) {
            $lineServiceIds = array_map('intval', (array) ($line['service_ids'] ?? []));
            if (array_intersect($matchServiceIds, $lineServiceIds) !== []) {
                $itemsSubtotal += (float) ($line['total_price'] ?? 0.0);
            }
        }

        return round(max(0.0, min($itemsSubtotal, $orderAmount)), 2);
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
        ?int $vendorId = null,
        array $context = []
    ): array {
        $discount = $this->discountQueryForDomain(Discount::DOMAIN_ORDER)
            ->where('code', $couponCode)
            ->first();

        if (! $discount) {
            return [
                'applied' => false,
                'discount' => null,
                'discount_amount' => 0.0,
            ];
        }

        return $this->applyIfEligible($discount, $orderAmount, $userId, $vendorId, false, $context);
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
        bool $alreadyAppliedOnOrder = false,
        array $context = []
    ): array {
        $none = [
            'applied' => false,
            'discount' => null,
            'discount_amount' => 0.0,
        ];
        $evaluation = $this->evaluateOrderDiscount($discount, array_merge([
            'order_amount' => round(max(0, $orderAmount), 2),
            'items_breakdown' => [],
            'items_count' => (int) ($context['items_count'] ?? 0),
            'vendor_id' => $vendorId,
            'client_id' => $userId,
            'branch_id' => $context['branch_id'] ?? null,
            'delivery_fee' => (float) ($context['delivery_fee'] ?? 0.0),
            'city' => $context['city'] ?? null,
            'client' => $userId ? Client::find($userId) : null,
            'order_count' => $userId ? $this->clientOrdersCount($userId) : 0,
        ], $context), 'en', $alreadyAppliedOnOrder);
        if (! $evaluation['success']) {
            return $none;
        }

        return [
            'applied' => true,
            'discount' => $discount,
            'discount_amount' => (float) ($evaluation['discount_amount'] ?? 0.0),
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
     * Localized success message after a coupon/discount is applied.
     */
    public function appliedSuccessMessage(Discount $discount, string $lang): string
    {
        return match ($discount->discount_type) {
            Discount::DISCOUNT_TYPE_DELIVERY_FREE => $lang === 'ar'
                ? 'تم تطبيق الخصم بنجاح'
                : 'Discount applied successfully',
            default => $lang === 'ar'
                ? 'تم تطبيق الكوبون بنجاح'
                : 'Coupon applied successfully',
        };
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
            'not_for_branch' => [
                'en' => 'This discount is not valid for the selected branch',
                'ar' => 'هذا الخصم غير صالح للفرع المحدد',
            ],
            'not_for_city' => [
                'en' => 'This discount is not valid for this city',
                'ar' => 'هذا الخصم غير صالح لهذه المدينة',
            ],
            'first_order_only' => [
                'en' => 'This discount is only available for the first order',
                'ar' => 'هذا الخصم متاح فقط لأول طلب',
            ],
            'repeat_orders_required' => [
                'en' => 'This discount requires previous completed orders',
                'ar' => 'هذا الخصم يتطلب طلبات مكتملة سابقة',
            ],
            'min_items_count' => [
                'en' => 'This discount requires a minimum item count',
                'ar' => 'هذا الخصم يتطلب حدًا أدنى من عدد القطع',
            ],
            'time_window' => [
                'en' => 'This discount is not active at the current time',
                'ar' => 'هذا الخصم غير متاح في الوقت الحالي',
            ],
            'wallet_topup_min' => [
                'en' => 'This wallet offer requires a higher top-up amount',
                'ar' => 'هذا العرض يتطلب مبلغ شحن أعلى للمحفظة',
            ],
            'segment_not_match' => [
                'en' => 'This discount is not available for your customer segment',
                'ar' => 'هذا الخصم غير متاح لفئة حسابك',
            ],
            'bundle_not_match' => [
                'en' => 'This discount requires a specific bundle',
                'ar' => 'هذا الخصم يتطلب باقة محددة',
            ],
            'no_delivery_leg' => [
                'en' => 'This is a delivery discount, but this order is a vendor pickup — there is no delivery fee to discount',
                'ar' => 'هذا الكود مخصص لخصم التوصيل، وهذا الطلب استلام من المغسلة وليس توصيل، فلا يوجد رسوم توصيل لتطبيق الخصم عليها',
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsBreakdown
     * @return array<string, mixed>
     */
    private function buildOrderContext(
        array $itemsBreakdown,
        float $orderAmount,
        int $vendorId,
        ?int $clientId,
        ?int $branchId,
        float $deliveryFee,
        ?string $city,
        bool $hasDelivery = true
    ): array {
        return [
            'items_breakdown' => $itemsBreakdown,
            'order_amount' => round($orderAmount, 2),
            'vendor_id' => $vendorId,
            'client_id' => $clientId,
            'branch_id' => $branchId,
            'delivery_fee' => round(max(0, $deliveryFee), 2),
            'has_delivery' => $hasDelivery,
            'city' => $city,
            'items_count' => array_sum(array_map(fn (array $line) => (int) ($line['quantity'] ?? 0), $itemsBreakdown)),
            'client' => $clientId ? Client::find($clientId) : null,
            'order_count' => $clientId ? $this->clientOrdersCount($clientId) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{success: bool, discount_amount?: float, delivery_discount_amount?: float, discount_base_amount?: float, message?: string, code?: int, errors?: array<string,mixed>}
     */
    private function evaluateOrderDiscount(Discount $discount, array $context, string $lang, bool $alreadyAppliedOnOrder): array
    {
        $msg = $this->getMessages();
        if ($discount->normalizedPromotionDomain() !== Discount::DOMAIN_ORDER) {
            return ['success' => false, 'message' => $msg['invalid_discount_type'][$lang], 'code' => 400];
        }

        if ($failure = $this->validateGenericAvailability($discount, $lang, $alreadyAppliedOnOrder)) {
            return $failure;
        }

        $usageCheck = $this->validateUsageConditions($discount, $context['items_breakdown'] ?? [], $lang);
        if (! $usageCheck['success']) {
            return $usageCheck;
        }

        $eligibilityCheck = $this->validateOrderContextEligibility($discount, $context, $lang);
        if (! $eligibilityCheck['success']) {
            return $eligibilityCheck;
        }

        // A delivery discount is meaningless on an order with no delivery leg at all
        // (client picks up from / drops off at the vendor themselves) — reject it
        // outright instead of silently "succeeding" with a zero-value discount.
        if ($this->isDeliveryDiscount($discount) && ! ($context['has_delivery'] ?? true)) {
            return ['success' => false, 'message' => $msg['no_delivery_leg'][$lang], 'code' => 400];
        }

        $discountBaseAmount = $this->resolveDiscountBaseAmount(
            $discount,
            (float) ($context['order_amount'] ?? 0.0),
            $context['items_breakdown'] ?? [],
            (float) ($context['delivery_fee'] ?? 0.0)
        );

        $discountAmount = 0.0;
        $deliveryDiscountAmount = 0.0;
        if ($this->isDeliveryDiscount($discount)) {
            $deliveryDiscountAmount = $this->isFullyFreeDelivery($discount)
                ? $discountBaseAmount
                : $this->calculateDiscountAmount($discount, $discountBaseAmount);
        } else {
            $discountAmount = $this->calculateDiscountAmount($discount, $discountBaseAmount);
        }

        return [
            'success' => true,
            'discount_amount' => round($discountAmount, 2),
            'delivery_discount_amount' => round($deliveryDiscountAmount, 2),
            'discount_base_amount' => round($discountBaseAmount, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{success: bool, bonus_amount?: float, message?: string, code?: int}
     */
    private function evaluateWalletTopupDiscount(Discount $discount, array $context, string $lang): array
    {
        if ($discount->normalizedPromotionDomain() !== Discount::DOMAIN_WALLET_TOPUP) {
            return ['success' => false, 'message' => $this->getMessages()['invalid_discount_type'][$lang], 'code' => 400];
        }

        if ($failure = $this->validateGenericAvailability($discount, $lang, false)) {
            return $failure;
        }

        $eligibilityCheck = $this->validateWalletTopupEligibility($discount, $context, $lang);
        if (! $eligibilityCheck['success']) {
            return $eligibilityCheck;
        }

        $baseAmount = round((float) ($context['topup_amount'] ?? 0.0), 2);
        if ($discount->wallet_bonus_amount !== null) {
            $bonusAmount = (float) $discount->wallet_bonus_amount;
        } elseif ($discount->wallet_bonus_percent !== null) {
            $bonusAmount = $baseAmount * ((float) $discount->wallet_bonus_percent / 100);
        } else {
            $bonusAmount = $this->calculateDiscountAmount($discount, $baseAmount);
        }

        return ['success' => true, 'bonus_amount' => round(max(0.0, $bonusAmount), 2)];
    }

    private function validateGenericAvailability(Discount $discount, string $lang, bool $alreadyAppliedOnOrder): ?array
    {
        $msg = $this->getMessages();

        if (! $discount->is_active) {
            return ['success' => false, 'message' => $msg['invalid_coupon'][$lang], 'code' => 400];
        }
        if ($discount->start_date && $discount->start_date > now()) {
            return ['success' => false, 'message' => $msg['not_yet_active'][$lang], 'code' => 400];
        }
        if ($discount->end_date && $discount->end_date < now()) {
            return ['success' => false, 'message' => $msg['expired'][$lang], 'code' => 400];
        }
        if (! $alreadyAppliedOnOrder && $discount->usage_limit && $discount->used_count >= $discount->usage_limit) {
            return ['success' => false, 'message' => $msg['usage_limit'][$lang], 'code' => 400];
        }
        if (! $this->passesTimeWindow($discount)) {
            return ['success' => false, 'message' => $msg['time_window'][$lang], 'code' => 400];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{success: bool, message?: string, code?: int, errors?: array<string,mixed>}
     */
    private function validateOrderContextEligibility(Discount $discount, array $context, string $lang): array
    {
        $msg = $this->getMessages();
        $orderAmount = (float) ($context['order_amount'] ?? 0.0);

        if ($discount->min_order_amount && $orderAmount < (float) $discount->min_order_amount) {
            return [
                'success' => false,
                'message' => $msg['min_order'][$lang].' '.number_format((float) $discount->min_order_amount, 2).' SAR',
                'code' => 400,
                'errors' => [
                    'order_amount' => $orderAmount,
                    'min_order_amount' => (float) $discount->min_order_amount,
                    'amount_needed' => round(max(0, (float) $discount->min_order_amount - $orderAmount), 2),
                ],
            ];
        }

        if ($discount->min_items_count && (int) ($context['items_count'] ?? 0) < (int) $discount->min_items_count) {
            return ['success' => false, 'message' => $msg['min_items_count'][$lang], 'code' => 400];
        }

        $eligibility = $this->checkDiscountEligibility(
            $discount,
            $context['client_id'] ?? null,
            (int) ($context['vendor_id'] ?? 0),
            $lang,
            $context
        );
        if (! $eligibility['success']) {
            return $eligibility;
        }

        if ($discount->first_order_only && (int) ($context['order_count'] ?? 0) > 0) {
            return ['success' => false, 'message' => $msg['first_order_only'][$lang], 'code' => 400];
        }

        if ($discount->min_repeat_orders && (int) ($context['order_count'] ?? 0) < (int) $discount->min_repeat_orders) {
            return ['success' => false, 'message' => $msg['repeat_orders_required'][$lang], 'code' => 400];
        }

        if (! $this->passesSegmentFilters($discount, $context['client'] ?? null, (int) ($context['order_count'] ?? 0))) {
            return ['success' => false, 'message' => $msg['segment_not_match'][$lang], 'code' => 400];
        }

        if (! $this->passesRequiredPieces($discount, $context['items_breakdown'] ?? [])) {
            return ['success' => false, 'message' => $msg['items_unavailable'][$lang], 'code' => 400];
        }

        if (! $this->passesBundleRules($discount, $context['items_breakdown'] ?? [])) {
            return ['success' => false, 'message' => $msg['bundle_not_match'][$lang], 'code' => 400];
        }

        return ['success' => true];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{success: bool, message?: string, code?: int}
     */
    private function validateWalletTopupEligibility(Discount $discount, array $context, string $lang): array
    {
        $msg = $this->getMessages();
        $amount = (float) ($context['topup_amount'] ?? 0.0);

        if ($discount->min_wallet_topup_amount && $amount < (float) $discount->min_wallet_topup_amount) {
            return ['success' => false, 'message' => $msg['wallet_topup_min'][$lang], 'code' => 400];
        }

        if ($discount->first_order_only && (int) ($context['wallet_topup_count'] ?? 0) > 0) {
            return ['success' => false, 'message' => $msg['first_order_only'][$lang], 'code' => 400];
        }

        $branchCity = $this->passesBranchAndCityFilters($discount, $context, $lang);
        if (! $branchCity['success']) {
            return $branchCity;
        }

        if (! $this->passesSegmentFilters($discount, $context['client'] ?? null, (int) ($context['order_count'] ?? 0))) {
            return ['success' => false, 'message' => $msg['segment_not_match'][$lang], 'code' => 400];
        }

        return ['success' => true];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{success: bool, message?: string, code?: int}
     */
    private function passesBranchAndCityFilters(Discount $discount, array $context, string $lang): array
    {
        $msg = $this->getMessages();
        $branchIds = array_values(array_unique(array_map('intval', (array) ($discount->branch_ids ?? []))));
        $branchId = (int) ($context['branch_id'] ?? 0);
        if ($branchIds !== [] && (! $branchId || ! in_array($branchId, $branchIds, true))) {
            return ['success' => false, 'message' => $msg['not_for_branch'][$lang], 'code' => 400];
        }

        $cityFilters = array_values(array_filter(array_map(fn ($city) => mb_strtolower(trim((string) $city)), (array) ($discount->city_names ?? []))));
        $city = mb_strtolower(trim((string) ($context['city'] ?? '')));
        if ($cityFilters !== [] && ($city === '' || ! in_array($city, $cityFilters, true))) {
            return ['success' => false, 'message' => $msg['not_for_city'][$lang], 'code' => 400];
        }

        $zoneIds = array_values(array_unique(array_map('intval', (array) ($discount->zone_ids ?? []))));
        if ($zoneIds !== []) {
            $orderZoneId = $branchId ? (int) (Branch::find($branchId)?->zone_id ?? 0) : 0;
            if (! $orderZoneId || ! in_array($orderZoneId, $zoneIds, true)) {
                return ['success' => false, 'message' => $msg['not_for_zone'][$lang], 'code' => 400];
            }
        }

        return ['success' => true];
    }

    private function passesTimeWindow(Discount $discount): bool
    {
        $days = array_values(array_unique(array_map('intval', (array) ($discount->active_days_of_week ?? []))));
        $now = now();
        if ($days !== [] && ! in_array((int) $now->dayOfWeekIso, $days, true)) {
            return false;
        }

        $from = $discount->active_time_from ? Carbon::parse((string) $discount->active_time_from)->format('H:i:s') : null;
        $to = $discount->active_time_to ? Carbon::parse((string) $discount->active_time_to)->format('H:i:s') : null;
        if (! $from || ! $to) {
            return true;
        }

        $current = $now->format('H:i:s');
        if ($from <= $to) {
            return $current >= $from && $current <= $to;
        }

        return $current >= $from || $current <= $to;
    }

    private function passesSegmentFilters(Discount $discount, ?Client $client, int $orderCount): bool
    {
        $filters = (array) ($discount->segment_filters ?? []);
        if ($filters === []) {
            return true;
        }
        if (! $client) {
            return false;
        }

        if (($filters['vip_only'] ?? false) && $orderCount < (int) ($filters['vip_min_orders'] ?? 5)) {
            return false;
        }
        if (isset($filters['min_orders']) && $orderCount < (int) $filters['min_orders']) {
            return false;
        }
        if (isset($filters['max_orders']) && $orderCount > (int) $filters['max_orders']) {
            return false;
        }
        if (isset($filters['last_active_days'])) {
            $lastOrderAt = Order::query()
                ->where('client_id', $client->id)
                ->latest('created_at')
                ->value('created_at');
            if ($lastOrderAt) {
                $days = now()->diffInDays(Carbon::parse((string) $lastOrderAt));
                if ($days < (int) $filters['last_active_days']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsBreakdown
     */
    private function passesRequiredPieces(Discount $discount, array $itemsBreakdown): bool
    {
        $requiredPieceIds = array_values(array_unique(array_map('intval', (array) ($discount->required_piece_ids ?? []))));
        if ($requiredPieceIds === []) {
            return true;
        }

        $presentPieceIds = array_values(array_unique(array_map(fn (array $line) => (int) ($line['piece_id'] ?? 0), $itemsBreakdown)));
        return array_diff($requiredPieceIds, $presentPieceIds) === [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsBreakdown
     */
    private function passesBundleRules(Discount $discount, array $itemsBreakdown): bool
    {
        $rules = (array) ($discount->bundle_rules ?? []);
        if ($rules === []) {
            return true;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $requiredServiceIds = array_values(array_unique(array_map('intval', (array) ($rule['service_ids'] ?? []))));
            $requiredPieceIds = array_values(array_unique(array_map('intval', (array) ($rule['piece_ids'] ?? []))));
            $minQuantity = max(1, (int) ($rule['min_quantity'] ?? 1));
            $matchedQuantity = 0;

            foreach ($itemsBreakdown as $line) {
                $lineServiceIds = array_map('intval', (array) ($line['service_ids'] ?? []));
                $linePieceId = (int) ($line['piece_id'] ?? 0);
                $serviceMatch = $requiredServiceIds === [] || array_intersect($requiredServiceIds, $lineServiceIds) !== [];
                $pieceMatch = $requiredPieceIds === [] || in_array($linePieceId, $requiredPieceIds, true);
                if ($serviceMatch && $pieceMatch) {
                    $matchedQuantity += (int) ($line['quantity'] ?? 0);
                }
            }

            if ($matchedQuantity < $minQuantity) {
                return false;
            }
        }

        return true;
    }

    private function isDeliveryDiscount(Discount $discount): bool
    {
        return $discount->discount_type === Discount::DISCOUNT_TYPE_DELIVERY_FREE
            || $discount->normalizedPromotionKind() === Discount::KIND_DELIVERY_DISCOUNT
            || (bool) $discount->applies_to_delivery;
    }

    /**
     * "توصيل مجاني" (free delivery) is its own discount_type in the admin UI, distinct
     * from "نسبة مئوية" (percentage) / "مبلغ ثابت" (fixed) — the admin form has no value
     * field for it (delivery is fully waived by definition), so `value` is unreliable
     * here and often ends up 0. Delivery-percentage discounts using the generic
     * applies_to_delivery/KIND_DELIVERY_DISCOUNT flag still use `value` normally.
     */
    private function isFullyFreeDelivery(Discount $discount): bool
    {
        return $discount->discount_type === Discount::DISCOUNT_TYPE_DELIVERY_FREE;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{applied: bool, discount: ?Discount, discount_amount: float, delivery_discount_amount: float, discount_base_amount: float}
     */
    private function selectBestAutomaticOrderDiscount(array $context, string $lang): array
    {
        $best = null;
        foreach ($this->automaticDiscountsForDomain(Discount::DOMAIN_ORDER) as $discount) {
            /** @var Discount $discount */
            $evaluation = $this->evaluateOrderDiscount($discount, $context, $lang, false);
            if (! $evaluation['success']) {
                continue;
            }

            $savings = (float) ($evaluation['discount_amount'] ?? 0.0) + (float) ($evaluation['delivery_discount_amount'] ?? 0.0);
            if ($savings <= 0) {
                continue;
            }
            if ($best === null || $this->isBetterCandidate($discount, $savings, $best['discount'], $best['savings'])) {
                $best = [
                    'discount' => $discount,
                    'savings' => $savings,
                    'discount_amount' => (float) ($evaluation['discount_amount'] ?? 0.0),
                    'delivery_discount_amount' => (float) ($evaluation['delivery_discount_amount'] ?? 0.0),
                    'discount_base_amount' => (float) ($evaluation['discount_base_amount'] ?? 0.0),
                ];
            }
        }

        if ($best === null) {
            return [
                'applied' => false,
                'discount' => null,
                'discount_amount' => 0.0,
                'delivery_discount_amount' => 0.0,
                'discount_base_amount' => 0.0,
            ];
        }

        return [
            'applied' => true,
            'discount' => $best['discount'],
            'discount_amount' => round((float) $best['discount_amount'], 2),
            'delivery_discount_amount' => round((float) $best['delivery_discount_amount'], 2),
            'discount_base_amount' => round((float) $best['discount_base_amount'], 2),
        ];
    }

    private function discountQueryForDomain(string $domain)
    {
        return Discount::with(['vendors', 'zones', 'clients'])
            ->where('is_active', true)
            ->where(function ($query) use ($domain) {
                $query->where('promotion_domain', $domain);
                if ($domain === Discount::DOMAIN_ORDER) {
                    $query->orWhereNull('promotion_domain');
                }
            });
    }

    private function automaticDiscountsForDomain(string $domain)
    {
        return $this->discountQueryForDomain($domain)
            ->where('is_automatic', true)
            ->orderByDesc('priority')
            ->orderByDesc('value')
            ->get();
    }

    private function isBetterCandidate(Discount $candidate, float $candidateSavings, Discount $current, float $currentSavings): bool
    {
        $candidatePriority = (int) ($candidate->priority ?? 100);
        $currentPriority = (int) ($current->priority ?? 100);
        if ($candidatePriority !== $currentPriority) {
            return $candidatePriority > $currentPriority;
        }
        if (round($candidateSavings, 2) !== round($currentSavings, 2)) {
            return $candidateSavings > $currentSavings;
        }

        return (int) $candidate->id > (int) $current->id;
    }

    private function clientOrdersCount(int $clientId): int
    {
        return Order::query()
            ->where('client_id', $clientId)
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    private function clientCompletedTopupCount(int $clientId): int
    {
        return DB::table('wallet_transactions')
            ->where('client_id', $clientId)
            ->where('type', 'credit')
            ->where('status', 'completed')
            ->where(function ($query) {
                $query->where('description', 'like', '%Wallet deposit%')
                    ->orWhere('description', 'like', '%إيداع%');
            })
            ->count();
    }
}
