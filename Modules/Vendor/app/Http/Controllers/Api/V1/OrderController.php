<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\OrderCatalogAvailabilityService;
use App\Support\OrderStatusLogPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Branch\Models\Branch;
use Modules\Branch\Services\BranchService;
use Modules\Chat\Services\ChatService;
use Modules\Discount\Services\DiscountService;
use Modules\Driver\Models\Driver;
use Modules\Order\Http\Resources\VendorOrderResource;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Order\Support\OrderItemsNormalizer;
use Modules\Piece\Models\Piece;
use Modules\Vendor\Support\VendorBranchFilter;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private BranchService $branchService,
        private ChatService $chatService,
        private DiscountService $discountService,
        private OrderCatalogAvailabilityService $catalogAvailabilityService,
    ) {}

    /**
     * Preview pricing for an existing order.
     *
     * Requires order_id. Prices NEW items (or order items if omitted), soft-applies
     * coupon (new code or order coupon), and recalculates delivery from the order's
     * pickup/delivery flags + saved addresses + branch/vendor rate.
     */
    public function calculate(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = (int) $employee->vendor_id;
        $lang = app()->getLocale();

        if ($request->has('items')) {
            $request->merge([
                'items' => OrderItemsNormalizer::normalize($request->input('items', [])),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'coupon_code' => ['nullable', 'string'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.piece_id' => ['required', 'exists:pieces,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
            'items.*.service_ids' => ['nullable', 'array', 'min:1'],
            'items.*.service_ids.*' => ['integer', 'exists:services,id'],
            'items.*.additional_service_ids' => ['nullable', 'array'],
            'items.*.additional_service_ids.*' => ['integer', 'exists:service_additions,id'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $resolved = $this->resolveCalculateContext($request, $vendorId);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        /** @var Branch $branch */
        $branch = $resolved['branch'];
        $clientId = $resolved['client_id'];
        $orderId = $resolved['order_id'];
        $orderDiscount = $resolved['discount'];
        $legacyDiscountAmount = (float) ($resolved['legacy_discount_amount'] ?? 0);
        /** @var Order $existingOrder */
        $existingOrder = $resolved['order'];
        $branchId = (int) $branch->id;

        $itemsInput = $request->input('items');
        $usingStoredOrderItems = ! is_array($itemsInput) || $itemsInput === [];
        if ($usingStoredOrderItems) {
            $itemsInput = $this->mapOrderItemsForCalculate($existingOrder);
            if ($itemsInput === []) {
                return errorResponse(__('order.items_not_available'), 400);
            }
        }

        try {
            $discountAmount = 0.0;
            $appliedDiscount = null;
            $totalAmount = 0.0;
            $itemsSummary = [];

            $pieceIds = collect($itemsInput)->pluck('piece_id')->unique();
            $pieces = Piece::with(['vendor', 'services'])
                ->whereIn('id', $pieceIds)
                ->get();

            if ($pieces->count() !== $pieceIds->count()) {
                return errorResponse(__('order.items_not_available'), 400);
            }

            $vendorIds = $pieces->pluck('vendor_id')->unique();
            if ($vendorIds->count() > 1 || (int) $vendorIds->first() !== $vendorId) {
                return errorResponse(__('order.items_vendor_not_match'), 400);
            }

            foreach ($itemsInput as $item) {
                $mainServiceIds = OrderItemsNormalizer::mainServiceIds($item);
                if ($mainServiceIds === []) {
                    return errorResponse(__('order.service_not_available', ['piece_name' => '']), 400);
                }

                $piece = $pieces->firstWhere('id', $item['piece_id']);
                if (! $piece) {
                    return errorResponse(__('order.items_not_available'), 400);
                }

                $servicesSummary = [];
                $servicesTotal = 0.0;
                $primaryService = null;
                $primaryServicePrice = 0.0;

                foreach ($mainServiceIds as $mainServiceId) {
                    if (! $usingStoredOrderItems) {
                        $availabilityError = $this->catalogAvailabilityService->validateOrderLineForNewOrder(
                            $branchId,
                            (int) $item['piece_id'],
                            (int) $mainServiceId,
                            $item['additional_service_ids'] ?? [],
                            $lang
                        );
                        if ($availabilityError !== null) {
                            return errorResponse($availabilityError, 400);
                        }
                    }

                    $service = $piece->services->firstWhere('id', $mainServiceId);
                    $storedServicePrice = (float) ($item['service_prices'][$mainServiceId] ?? 0);
                    if (! $service) {
                        if (! $usingStoredOrderItems) {
                            $pieceName = \App\Support\OrderItemDisplayNames::pieceName($piece, $branchId, $lang);

                            return errorResponse(__('order.service_not_available', ['piece_name' => $pieceName]), 400);
                        }

                        $serviceLabel = (string) ($item['service_names'][$mainServiceId] ?? '');
                        $servicesTotal += $storedServicePrice;
                        $servicesSummary[] = [
                            'id' => (int) $mainServiceId,
                            'service_id' => (int) $mainServiceId,
                            'name' => $serviceLabel,
                            'service_name' => $serviceLabel,
                            'price' => $storedServicePrice,
                        ];

                        if ($primaryService === null) {
                            $primaryServicePrice = $storedServicePrice;
                        }

                        continue;
                    }

                    $servicePiecePrice = (float) $service->getPriceForPieceAtBranch($piece->id, $branchId);
                    $servicesTotal += $servicePiecePrice;
                    $serviceLabel = \App\Support\OrderItemDisplayNames::serviceName($service, $branchId, $lang);
                    $servicesSummary[] = [
                        'id' => $service->id,
                        'service_id' => $service->id,
                        'name' => $serviceLabel,
                        'service_name' => $serviceLabel,
                        'price' => $servicePiecePrice,
                    ];

                    if ($primaryService === null) {
                        $primaryService = $service;
                        $primaryServicePrice = $servicePiecePrice;
                    }
                }

                $additionalServicesSummary = [];
                $additionalServicesTotal = 0.0;

                if (! empty($item['additional_service_ids'])) {
                    $storedAdditionalServices = collect($item['additional_services'] ?? [])->keyBy('id');
                    foreach (array_unique($item['additional_service_ids']) as $additionalServiceId) {
                        $additionModel = \Modules\Service\Models\ServiceAddition::find($additionalServiceId);
                        $storedAddition = $storedAdditionalServices->get($additionalServiceId);
                        if (! $additionModel && ! $storedAddition) {
                            continue;
                        }
                        $additionalPrice = $additionModel
                            ? (float) $additionModel->getPriceForPieceAtBranch($piece->id, $branchId)
                            : (float) ($storedAddition['price'] ?? 0);
                        $additionalServicesTotal += $additionalPrice;
                        if ($additionModel) {
                            $additionalServicesSummary[] = \App\Support\OrderItemDisplayNames::additionalServiceLine(
                                $additionModel,
                                $branchId,
                                $lang,
                                $additionalPrice
                            );
                        } else {
                            $additionalServicesSummary[] = [
                                'id' => (int) $additionalServiceId,
                                'name' => (string) ($storedAddition['name'] ?? 'Addition'),
                                'price' => $additionalPrice,
                                'quantity' => 1,
                                'total_price' => $additionalPrice,
                            ];
                        }
                    }
                }

                $unitPrice = $servicesTotal;
                $lineTotalPrice = $unitPrice + $additionalServicesTotal;
                $itemTotal = $lineTotalPrice * (int) $item['quantity'];
                $totalAmount += $itemTotal;

                $quantity = (int) $item['quantity'];
                for ($i = 0; $i < $quantity; $i++) {
                    $itemsSummary[] = [
                        'piece' => [
                            'id' => $piece->id,
                            'name' => \App\Support\OrderItemDisplayNames::pieceName($piece, $branchId, $lang),
                            'icon' => $piece->iconRelation?->full_path,
                        ],
                        'service' => $servicesSummary[0] ?? [
                            'id' => $primaryService->id,
                            'service_id' => $primaryService->id,
                            'name' => \App\Support\OrderItemDisplayNames::serviceName($primaryService, $branchId, $lang),
                            'service_name' => \App\Support\OrderItemDisplayNames::serviceName($primaryService, $branchId, $lang),
                            'price' => $primaryServicePrice,
                        ],
                        'services' => $servicesSummary,
                        'additional_services' => $additionalServicesSummary,
                        'additional_services_total' => $additionalServicesTotal,
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'total_price' => $lineTotalPrice,
                        'note' => $item['note'] ?? null,
                    ];
                }
            }

            // Soft-apply coupon against NEW item totals.
            if ($request->filled('coupon_code')) {
                $soft = $this->discountService->softApplyCouponCode(
                    (string) $request->coupon_code,
                    (float) $totalAmount,
                    $clientId,
                    $vendorId
                );
                if ($soft['applied']) {
                    $appliedDiscount = $soft['discount'];
                    $discountAmount = (float) $soft['discount_amount'];
                }
            } elseif ($orderDiscount) {
                $soft = $this->discountService->applyIfEligible(
                    $orderDiscount,
                    (float) $totalAmount,
                    $clientId,
                    $vendorId,
                    true
                );
                if ($soft['applied']) {
                    $appliedDiscount = $soft['discount'];
                    $discountAmount = (float) $soft['discount_amount'];
                }
            } elseif ($legacyDiscountAmount > 0) {
                $discountAmount = min($legacyDiscountAmount, (float) $totalAmount);
            }

            $pickupAtVendor = (bool) $existingOrder->pickup_at_vendor;
            $deliveryAtVendor = (bool) $existingOrder->delivery_at_vendor;

            // Vendor calculate for an existing order must keep the stored delivery fee.
            // Recomputing from distance creates half-leg floats (e.g. 18.56097...) that
            // diverge from tracking/order-details which use orders.delivery_fee.
            $storedDeliveryFee = round((float) $existingOrder->delivery_fee, 2);
            $splitFee = ($pickupAtVendor || $deliveryAtVendor)
                ? $storedDeliveryFee
                : round($storedDeliveryFee / 2, 2);
            $deliveryFees = [
                'delivery_fee' => $storedDeliveryFee,
                'pickup_fee' => $pickupAtVendor ? 0.0 : $splitFee,
                'delivery_fee_amount' => $deliveryAtVendor ? 0.0 : $splitFee,
                'total_distance_km' => round((float) ($existingOrder->distance ?? 0), 2),
                'distance' => round((float) ($existingOrder->distance ?? 0), 2),
            ];

            $pricing = Order::calculatePricingTotals(
                (float) $totalAmount,
                (float) $discountAmount,
                (float) $deliveryFees['delivery_fee']
            );

            $acceptedItems = [];
            $rejectedItems = [];
            if ($usingStoredOrderItems) {
                ['accepted_items' => $acceptedItems, 'rejected_items' => $rejectedItems] =
                    $this->buildReviewedItemsForVendorResponse($existingOrder, $lang);
                $itemsSummary = $this->buildCalculateSummaryItems($acceptedItems, $rejectedItems);
            }

            return successResponse([
                'order_id' => $orderId,
                'branch_id' => $branchId,
                'have_coupon' => $appliedDiscount !== null || $discountAmount > 0,
                'summary' => [
                    'items' => $itemsSummary,
                    'items_count' => count($itemsSummary),
                    'subtotal' => (float) $pricing['subtotal'],
                    'discount_amount' => (float) $pricing['discount_amount'],
                    'subtotal_after_discount' => (float) $pricing['subtotal_after_discount'],
                    'tax_percentage' => (float) $pricing['tax_percentage'],
                    'tax_amount' => (float) $pricing['tax_amount'],
                    'delivery_fee' => (float) $pricing['delivery_fee'],
                    'pickup_fee' => (float) $deliveryFees['pickup_fee'],
                    'delivery_fee_amount' => (float) $deliveryFees['delivery_fee_amount'],
                    'total_distance_km' => (float) $deliveryFees['total_distance_km'],
                    'distance' => (float) $deliveryFees['distance'],
                    'final_amount' => (float) $pricing['final_amount'],
                    'pickup_at_vendor' => $pickupAtVendor,
                    'delivery_at_vendor' => $deliveryAtVendor,
                ],
                'accepted_items' => $acceptedItems,
                'rejected_items' => $rejectedItems,
                'discount' => $appliedDiscount ? [
                    'code' => $appliedDiscount->code,
                    'name' => $appliedDiscount->name,
                    'type' => $appliedDiscount->type,
                    'value' => (float) $appliedDiscount->value,
                    'discount_amount' => (float) $discountAmount,
                ] : ($discountAmount > 0 ? [
                    'code' => null,
                    'name' => null,
                    'type' => null,
                    'value' => null,
                    'discount_amount' => (float) $discountAmount,
                ] : null),
            ], __('order.order_calculation_completed'));
        } catch (\Throwable $e) {
            return serverErrorResponse(__('order.failed_to_calculate').': '.$e->getMessage());
        }
    }

    /**
     * Recalculate pickup/delivery fees using the order's method flags and addresses.
     *
     * @return array{
     *     delivery_fee: float,
     *     pickup_fee: float,
     *     delivery_fee_amount: float,
     *     total_distance_km: float,
     *     distance: float
     * }|JsonResponse
     */
    private function computeDeliveryFeesFromOrder(Order $order, Branch $branch): array|JsonResponse
    {
        $pickupAtVendor = (bool) $order->pickup_at_vendor;
        $deliveryAtVendor = (bool) $order->delivery_at_vendor;

        if ($pickupAtVendor && $deliveryAtVendor) {
            return [
                'delivery_fee' => 0.0,
                'pickup_fee' => 0.0,
                'delivery_fee_amount' => 0.0,
                'total_distance_km' => 0.0,
                'distance' => 0.0,
            ];
        }

        $order->loadMissing(['pickupAddress', 'deliveryAddress', 'branch.vendor']);
        $vendor = $branch->vendor ?? $order->branch?->vendor;

        if (! $branch->latitude || ! $branch->longitude) {
            return errorResponse(__('order.vendor_location_not_available'), 400);
        }

        $deliveryPricePerKm = (float) ($vendor?->delivery_price_per_km
            ?? \Modules\Admin\Models\AdminSetting::getValue('delivery_price_per_km', 5));

        $pickupFee = 0.0;
        $deliveryFeeAmount = 0.0;
        $totalDistance = 0.0;

        if (! $pickupAtVendor) {
            $pickupAddress = $order->pickupAddress;
            if (! $pickupAddress || ! $pickupAddress->latitude || ! $pickupAddress->longitude) {
                return errorResponse(__('order.pickup_address_location_not_available'), 400);
            }

            $pickupDistance = $this->calculateDistanceKm(
                (float) $pickupAddress->latitude,
                (float) $pickupAddress->longitude,
                (float) $branch->latitude,
                (float) $branch->longitude
            );
            $totalDistance += $pickupDistance;
            $pickupFee = $pickupDistance * $deliveryPricePerKm;
        }

        if (! $deliveryAtVendor) {
            $deliveryAddress = $order->deliveryAddress;
            if (! $deliveryAddress || ! $deliveryAddress->latitude || ! $deliveryAddress->longitude) {
                return errorResponse(__('order.delivery_address_location_not_available'), 400);
            }

            $deliveryDistance = $this->calculateDistanceKm(
                (float) $branch->latitude,
                (float) $branch->longitude,
                (float) $deliveryAddress->latitude,
                (float) $deliveryAddress->longitude
            );
            $totalDistance += $deliveryDistance;
            $deliveryFeeAmount = $deliveryDistance * $deliveryPricePerKm;
        }

        $deliveryFee = $pickupFee + $deliveryFeeAmount;

        return [
            'delivery_fee' => round((float) $deliveryFee, 2),
            'pickup_fee' => round((float) $pickupFee, 2),
            'delivery_fee_amount' => round((float) $deliveryFeeAmount, 2),
            'total_distance_km' => (float) round($totalDistance, 2),
            'distance' => (float) round($totalDistance, 2),
        ];
    }

    /**
     * Reuse the vendor order-details grouping so calculate matches reviewed orders.
     *
     * @return array{accepted_items: list<array<string,mixed>>, rejected_items: list<array<string,mixed>>}
     */
    private function buildReviewedItemsForVendorResponse(Order $order, string $locale): array
    {
        $order->loadMissing([
            'items.piece.iconRelation',
            'items.service.iconRelation',
            'items.additionalServicesPivot.serviceAddition.iconRelation',
        ]);

        $uploadService = app(\App\Services\UploadFilesService::class);
        $branchId = (int) ($order->branch_id ?? 0);

        $items = collect(\Modules\Order\Support\OrderItemGrouper::toApiLines(
            $order->items,
            $branchId,
            $locale,
            fn ($item) => $item->images ? $uploadService->getFullUrl($item->images) : null
        ))->map(function (array $g) use ($order, $branchId, $locale) {
            $primaryItemId = $g['id'];
            $primaryItem = $order->items->firstWhere('id', $primaryItemId);
            $pieceName = $g['piece']['name'] ?? 'Item';
            $groupItemIds = $g['ids'] ?? [$primaryItemId];

            $modifiers = [];
            if ($primaryItem && $primaryItem->notes) {
                $notes = json_decode($primaryItem->notes, true);
                if (is_array($notes)) {
                    foreach ($notes as $modifier) {
                        $modifiers[] = [
                            'modifier_id' => $modifier['id'] ?? null,
                            'modifier_name' => $modifier['name'] ?? 'Modifier',
                            'modifier_price' => (float) ($modifier['price'] ?? 0),
                        ];
                    }
                }
            }

            $serviceAdditions = [];
            foreach ($groupItemIds as $itemId) {
                $itemModel = $order->items->firstWhere('id', $itemId);
                if (! $itemModel || ! $itemModel->relationLoaded('additionalServicesPivot')) {
                    continue;
                }
                foreach ($itemModel->additionalServicesPivot as $pivot) {
                    $addition = $pivot->serviceAddition;
                    if (! $addition) {
                        continue;
                    }
                    $qty = (int) ($pivot->quantity ?? 1);
                    $price = \App\Support\OrderItemDisplayNames::storedAdditionalServiceUnitPrice($pivot);
                    $serviceAdditions[] = array_merge([
                        'id' => $addition->id,
                        'name' => \App\Support\OrderItemDisplayNames::additionalServiceName($addition, $branchId, $locale) ?: 'Addition',
                        'price' => $price,
                        'quantity' => $qty,
                        'total_price' => $price * $qty,
                        'icon' => \App\Support\OrderItemDisplayNames::additionalServiceIconUrl($addition, $branchId),
                        'status' => $pivot->vendor_status ?? 'accepted',
                        'vendor_status' => $pivot->vendor_status ?? 'accepted',
                        'vendor_notes' => $pivot->vendor_notes,
                    ], \App\Support\CatalogActivePresenter::serviceAddition($addition, $branchId));
                }
            }

            $servicesData = [];
            foreach ($g['services'] ?? [] as $svc) {
                $svcEntry = [
                    'id' => $svc['id'],
                    'name' => $svc['name'] ?? '',
                    'price' => (float) ($svc['price'] ?? 0),
                    'icon' => $svc['icon'] ?? null,
                ];
                $serviceModel = $primaryItem?->piece?->services->firstWhere('id', $svc['id']);
                if ($serviceModel) {
                    $svcEntry = array_merge($svcEntry, [
                        'description' => $serviceModel->getTranslation('description', $locale),
                        'icon' => \App\Support\OrderItemDisplayNames::serviceIconUrl($serviceModel, $branchId),
                    ], \App\Support\CatalogActivePresenter::service($serviceModel, $branchId));
                }
                $servicesData[] = $svcEntry;
            }

            $pieceData = null;
            if ($primaryItem && $primaryItem->piece) {
                $pieceData = array_merge([
                    'id' => $primaryItem->piece->id,
                    'name' => $pieceName,
                    'icon' => \App\Support\OrderItemDisplayNames::pieceIconUrl($primaryItem->piece),
                ], \App\Support\CatalogActivePresenter::piece($primaryItem->piece, $branchId));
            }

            $groupModels = collect($groupItemIds)
                ->map(fn ($itemId) => $order->items->firstWhere('id', $itemId))
                ->filter()
                ->values();
            $servicesTotalPrice = (float) collect($servicesData)->sum('price');
            $originalUnitPrice = (float) $groupModels->sum(fn ($item) => (float) ($item->service_price ?? 0));
            $originalTotalPrice = (float) $groupModels->sum(
                fn ($item) => (float) ($item->original_total_price ?? $item->total_price ?? 0)
            );

            return [
                'item_id' => $primaryItemId,
                'item_ids' => $groupItemIds,
                'piece_id' => $primaryItem->piece_id ?? null,
                'item_name' => $pieceName,
                'service_price' => $servicesTotalPrice,
                'additional_services_total' => (float) ($g['additional_services_total'] ?? 0),
                'quantity' => (int) ($g['quantity'] ?? 1),
                'unit_price' => (float) ($g['unit_price'] ?? 0),
                'total_price' => (float) ($g['total_price'] ?? 0),
                'status' => $g['status'] ?? 'accepted',
                'original_quantity' => $primaryItem->original_quantity ?? $primaryItem->quantity ?? null,
                'original_unit_price' => $originalUnitPrice,
                'original_total_price' => $originalTotalPrice,
                'modified_quantity' => $primaryItem->modified_quantity ?? null,
                'modified_unit_price' => $primaryItem->modified_unit_price !== null ? (float) $primaryItem->modified_unit_price : null,
                'modified_total_price' => $primaryItem->modified_total_price !== null ? (float) $primaryItem->modified_total_price : null,
                'vendor_notes' => $primaryItem->vendor_notes ?? null,
                'note' => $g['note'] ?? null,
                'image' => $g['image'] ?? null,
                'modifiers' => $modifiers,
                'service_additions' => $serviceAdditions,
                'service' => $servicesData[0] ?? null,
                'services' => $servicesData,
                'piece' => $pieceData,
            ];
        });

        $acceptedItems = $items->filter(fn ($i) => ($i['status'] ?? 'accepted') !== 'rejected')->values();
        $rejectedItems = $items->filter(fn ($i) => ($i['status'] ?? 'accepted') === 'rejected')->values();
        // Fully rejected pieces: fold additions into services so mobile shows them on one line.
        $rejectedItems = $rejectedItems->map(fn (array $item) => $this->foldAdditionsIntoServicesForDisplay($item))->values();

        $rejectedAdditionItems = $acceptedItems
            ->map(function (array $item) {
                $rejectedAdditions = collect($item['service_additions'] ?? [])
                    ->filter(fn ($addition) => (($addition['vendor_status'] ?? $addition['status'] ?? 'accepted') === 'rejected'))
                    ->values()
                    ->all();

                if ($rejectedAdditions === []) {
                    return null;
                }

                $additionsTotal = (float) collect($rejectedAdditions)->sum(fn ($a) => (float) ($a['total_price'] ?? 0));
                $servicesFromRejectedAdditions = collect($rejectedAdditions)
                    ->map(fn (array $addition) => [
                        'id' => $addition['id'],
                        'name' => $addition['name'],
                        'price' => (float) ($addition['price'] ?? 0),
                        'icon' => $addition['icon'] ?? null,
                    ])
                    ->values()
                    ->all();
                $pieceQuantity = (int) ($item['quantity'] ?? 1);

                return [
                    'item_id' => $item['item_id'],
                    'item_ids' => $item['item_ids'] ?? [$item['item_id']],
                    'piece_id' => $item['piece_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'service_price' => 0.0,
                    'additional_services_total' => $additionsTotal,
                    'quantity' => $pieceQuantity,
                    'unit_price' => $additionsTotal,
                    'total_price' => $additionsTotal,
                    'status' => 'rejected',
                    'original_quantity' => $pieceQuantity,
                    'original_unit_price' => $additionsTotal,
                    'original_total_price' => $additionsTotal,
                    'modified_quantity' => null,
                    'modified_unit_price' => null,
                    'modified_total_price' => null,
                    'vendor_notes' => null,
                    'note' => $item['note'] ?? null,
                    'image' => $item['image'] ?? null,
                    'modifiers' => [],
                    'service_additions' => [],
                    'service' => $servicesFromRejectedAdditions[0] ?? null,
                    'services' => $servicesFromRejectedAdditions,
                    'piece' => $item['piece'] ?? null,
                ];
            })
            ->filter()
            ->values();

        $rejectedItems = $rejectedItems->concat($rejectedAdditionItems)->values();

        $acceptedItems = $acceptedItems->map(function (array $item) {
            $acceptedAdditions = collect($item['service_additions'] ?? [])
                ->filter(fn ($addition) => (($addition['vendor_status'] ?? $addition['status'] ?? 'accepted') !== 'rejected'))
                ->values()
                ->all();
            $item['service_additions'] = $acceptedAdditions;
            $item['additional_services_total'] = (float) collect($acceptedAdditions)
                ->sum(fn ($addition) => (float) ($addition['total_price'] ?? 0));

            return $item;
        })->values();

        return [
            'accepted_items' => $acceptedItems->values()->toArray(),
            'rejected_items' => $rejectedItems->values()->toArray(),
        ];
    }

    /**
     * Mobile renders services as the parenthetical label; fold additions into services
     * for fully rejected pieces so the line is not missing extra services.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function foldAdditionsIntoServicesForDisplay(array $item): array
    {
        $additions = collect($item['service_additions'] ?? [])->values();
        if ($additions->isEmpty()) {
            return $item;
        }

        $additionServices = $additions
            ->map(fn (array $addition) => [
                'id' => $addition['id'],
                'name' => $addition['name'],
                'price' => (float) ($addition['price'] ?? 0),
                'icon' => $addition['icon'] ?? null,
            ])
            ->all();

        $item['services'] = array_values(array_merge($item['services'] ?? [], $additionServices));
        if (($item['service'] ?? null) === null && $item['services'] !== []) {
            $item['service'] = $item['services'][0];
        }
        // Avoid double rendering: mobile expands service_additions as separate sub-rows.
        $item['service_additions'] = [];

        return $item;
    }

    /**
     * Flatten calculate summary items so mobile can render accepted and rejected rows consistently.
     *
     * @param  list<array<string,mixed>>  $acceptedItems
     * @param  list<array<string,mixed>>  $rejectedItems
     * @return list<array<string,mixed>>
     */
    private function buildCalculateSummaryItems(array $acceptedItems, array $rejectedItems): array
    {
        $accepted = collect($acceptedItems)->map(function (array $item) {
            return [
                'piece' => $item['piece'] ?? null,
                'service' => $item['service'] ?? null,
                'services' => $item['services'] ?? [],
                'additional_services' => $item['service_additions'] ?? [],
                'additional_services_total' => (float) ($item['additional_services_total'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'total_price' => (float) ($item['total_price'] ?? 0),
                'original_unit_price' => (float) ($item['original_unit_price'] ?? $item['unit_price'] ?? 0),
                'original_total_price' => (float) ($item['original_total_price'] ?? $item['total_price'] ?? 0),
                'status' => $item['status'] ?? 'accepted',
                'note' => $item['note'] ?? null,
            ];
        });

        $rejected = collect($rejectedItems)->map(function (array $item) {
            $item = $this->foldAdditionsIntoServicesForDisplay($item);

            return [
                'piece' => $item['piece'] ?? null,
                'service' => $item['service'] ?? null,
                'services' => $item['services'] ?? [],
                'additional_services' => $item['service_additions'] ?? [],
                'additional_services_total' => (float) ($item['additional_services_total'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'unit_price' => 0.0,
                'total_price' => 0.0,
                'original_unit_price' => (float) ($item['original_unit_price'] ?? $item['unit_price'] ?? 0),
                'original_total_price' => (float) ($item['original_total_price'] ?? $item['total_price'] ?? 0),
                'status' => 'rejected',
                'note' => $item['note'] ?? null,
            ];
        });

        return $accepted->concat($rejected)->values()->all();
    }

    private function calculateDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function mapOrderItemsForCalculate(Order $order): array
    {
        $order->loadMissing(['items.additionalServicesPivot', 'items.service', 'items.piece']);

        $result = [];
        foreach (\Modules\Order\Support\OrderItemGrouper::buckets($order->items) as $group) {
            foreach ($group->groupBy(fn ($item) => $item->vendor_status ?? 'accepted') as $status => $statusItems) {
                if ($status === 'rejected') {
                    continue;
                }

                $primary = collect($statusItems)->first();
                $additionIds = collect($primary->additionalServicesPivot ?? [])
                    ->filter(fn ($pivot) => ($pivot->vendor_status ?? 'accepted') !== 'rejected')
                    ->pluck('service_addition_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                $serviceIds = collect($statusItems)->pluck('service_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
                $servicePrices = collect($statusItems)
                    ->mapWithKeys(fn ($item) => [(int) $item->service_id => (float) ($item->service_price ?? 0)])
                    ->all();
                $serviceNames = collect($statusItems)
                    ->mapWithKeys(function ($item) {
                        $label = $item->service
                            ? \App\Support\OrderItemDisplayNames::serviceName($item->service, (int) $item->branch_id, app()->getLocale())
                            : '';

                        return [(int) $item->service_id => $label];
                    })
                    ->all();
                $additionDetails = collect($primary->additionalServicesPivot ?? [])
                    ->filter(fn ($pivot) => ($pivot->vendor_status ?? 'accepted') !== 'rejected')
                    ->map(fn ($pivot) => [
                        'id' => (int) $pivot->service_addition_id,
                        'name' => $pivot->serviceAddition
                            ? \App\Support\OrderItemDisplayNames::additionalServiceName($pivot->serviceAddition, (int) $primary->branch_id, app()->getLocale())
                            : 'Addition',
                        'price' => (float) \App\Support\OrderItemDisplayNames::storedAdditionalServiceUnitPrice($pivot),
                    ])
                    ->values()
                    ->all();

                if ($serviceIds === []) {
                    continue;
                }

                $result[] = [
                    'piece_id' => (int) $primary->piece_id,
                    'service_id' => (int) ($serviceIds[0] ?? $primary->service_id),
                    'service_ids' => $serviceIds,
                    'service_prices' => $servicePrices,
                    'service_names' => $serviceNames,
                    'quantity' => max(1, (int) $primary->quantity),
                    'additional_service_ids' => $additionIds,
                    'additional_services' => $additionDetails,
                    'note' => $primary->notes,
                ];
            }
        }

        return $result;
    }

    /**
     * Resolve existing order context for calculate (branch, coupon, addresses).
     *
     * @return array{
     *     branch: Branch,
     *     client_id: int,
     *     order_id: int,
     *     discount: ?\Modules\Discount\Models\Discount,
     *     legacy_discount_amount: float,
     *     order: Order
     * }|JsonResponse
     */
    private function resolveCalculateContext(Request $request, int $vendorId): array|JsonResponse
    {
        $order = Order::with([
            'branch.vendor',
            'discount',
            'items.additionalServices',
            'pickupAddress',
            'deliveryAddress',
        ])
            ->where('id', (int) $request->order_id)
            ->whereHas('branch', fn ($q) => $q->where('vendor_id', $vendorId))
            ->first();

        if (! $order || ! $order->branch) {
            return notFoundResponse(__('order.order_not_found'));
        }

        if (! $order->branch->is_active) {
            return errorResponse(__('branch.not_active'), 400);
        }

        return [
            'branch' => $order->branch,
            'client_id' => (int) $order->client_id,
            'order_id' => (int) $order->id,
            'discount' => $order->discount,
            'legacy_discount_amount' => $order->discount ? 0.0 : (float) $order->discount_amount,
            'order' => $order,
        ];
    }

    /**
     * Get all orders for vendor
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $status = $request->query('status');

        // Use BranchService
        $branches = $this->branchService->getBranchesByVendor($vendorId);
        $branchIds = VendorBranchFilter::hasFilter($request)
            ? VendorBranchFilter::resolveIds($request, $vendorId)->all()
            : $branches->pluck('id')->toArray();

        $filters = [
            'limit' => $request->query('limit', 15),
            'with' => ['items.piece', 'items.service', 'client', 'driver', 'pickupAddress', 'branch', 'discount'],
        ];

        if ($status) {
            if ($status === 'current') {
                $filters['vendor_tab'] = 'current';
            } elseif ($status === 'completed') {
                $filters['vendor_tab'] = 'completed';
            } elseif ($status === 'cancelled') {
                $filters['status'] = OrderStatus::CANCELLED->value;
            } else {
                $filters['status'] = $status;
            }
        }

        $orders = $this->orderService->getVendorOrders($branchIds, $filters);

        return successResponse(
            VendorOrderResource::collection($orders),
            __('order.orders_retrieved')
        );
    }

    /**
     * Get order details
     */
    public function show(Request $request, $orderId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id');

        $order = Order::where('id', $orderId)
            ->whereIn('branch_id', $branchIds)
            ->with([
                // Do not withTrashed(): replaceOrderItems soft-deletes old lines on
                // client edit; exposing them here makes the app review stale item_ids.
                'items' => fn ($q) => $q->with([
                    'piece.iconRelation',
                    'service.iconRelation',
                    'additions',
                    'additionalServicesPivot.serviceAddition.iconRelation',
                ]),
                'client',
                'driver',
                'pickupDriver',
                'deliveryDriver',
                'branch',
                'latestPayment',
                'statusLogs',
                'pickupAddress',
                'deliveryAddress',
                'discount',
            ])
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $uploadService = app(\App\Services\UploadFilesService::class);
        $locale = app()->getLocale();

        $branchId = (int) ($order->branch_id ?? 0);

        $items = collect(\Modules\Order\Support\OrderItemGrouper::toApiLines(
            $order->items,
            $branchId,
            $locale,
            fn ($item) => $item->images ? $uploadService->getFullUrl($item->images) : null
        ))->map(function (array $g) use ($order, $uploadService, $branchId, $locale) {
            $primaryItemId = $g['id'];
            $primaryItem = $order->items->firstWhere('id', $primaryItemId);
            $pieceName = $g['piece']['name'] ?? 'Item';
            $groupItemIds = $g['ids'] ?? [$primaryItemId];

            $modifiers = [];
            if ($primaryItem && $primaryItem->notes) {
                $notes = json_decode($primaryItem->notes, true);
                if (is_array($notes)) {
                    foreach ($notes as $modifier) {
                        $modifiers[] = [
                            'modifier_id' => $modifier['id'] ?? null,
                            'modifier_name' => $modifier['name'] ?? 'Modifier',
                            'modifier_price' => (float) ($modifier['price'] ?? 0),
                        ];
                    }
                }
            }

            // Collect service additions with CatalogActivePresenter from all grouped items
            $serviceAdditions = [];
            foreach ($groupItemIds as $itemId) {
                $itemModel = $order->items->firstWhere('id', $itemId);
                if (! $itemModel || ! $itemModel->relationLoaded('additionalServicesPivot')) {
                    continue;
                }
                foreach ($itemModel->additionalServicesPivot as $pivot) {
                    $addition = $pivot->serviceAddition;
                    if (! $addition) {
                        continue;
                    }
                    $qty = (int) ($pivot->quantity ?? 1);
                    $price = \App\Support\OrderItemDisplayNames::storedAdditionalServiceUnitPrice($pivot);
                    $serviceAdditions[] = array_merge([
                        'id' => $addition->id,
                        'name' => \App\Support\OrderItemDisplayNames::additionalServiceName($addition, $branchId, $locale) ?: 'Addition',
                        'price' => $price,
                        'quantity' => $qty,
                        'total_price' => $price * $qty,
                        'icon' => \App\Support\OrderItemDisplayNames::additionalServiceIconUrl($addition, $branchId),
                        'status' => $pivot->vendor_status ?? 'accepted',
                        'vendor_status' => $pivot->vendor_status ?? 'accepted',
                        'vendor_notes' => $pivot->vendor_notes,
                    ], \App\Support\CatalogActivePresenter::serviceAddition($addition, $branchId));
                }
            }

            // Build services array with CatalogActivePresenter
            $servicesData = [];
            foreach ($g['services'] ?? [] as $svc) {
                $svcEntry = [
                    'id' => $svc['id'],
                    'name' => $svc['name'] ?? '',
                    'price' => (float) ($svc['price'] ?? 0),
                    'icon' => $svc['icon'] ?? null,
                ];
                $serviceModel = $primaryItem?->piece?->services->firstWhere('id', $svc['id']);
                if ($serviceModel) {
                    $svcEntry = array_merge($svcEntry, [
                        'description' => $serviceModel->getTranslation('description', $locale),
                        'icon' => \App\Support\OrderItemDisplayNames::serviceIconUrl($serviceModel, $branchId),
                    ], \App\Support\CatalogActivePresenter::service($serviceModel, $branchId));
                }
                $servicesData[] = $svcEntry;
            }

            $pieceData = null;
            if ($primaryItem && $primaryItem->piece) {
                $pieceData = array_merge([
                    'id' => $primaryItem->piece->id,
                    'name' => $pieceName,
                    'icon' => \App\Support\OrderItemDisplayNames::pieceIconUrl($primaryItem->piece),
                ], \App\Support\CatalogActivePresenter::piece($primaryItem->piece, $branchId));
            }

            $groupModels = collect($groupItemIds)
                ->map(fn ($itemId) => $order->items->firstWhere('id', $itemId))
                ->filter()
                ->values();
            $servicesTotalPrice = (float) collect($servicesData)->sum('price');
            $originalUnitPrice = (float) $groupModels->sum(
                fn ($item) => (float) ($item->service_price ?? 0)
            );
            $originalTotalPrice = (float) $groupModels->sum(
                fn ($item) => (float) ($item->original_total_price ?? $item->total_price ?? 0)
            );

            return [
                'item_id' => $primaryItemId,
                'item_ids' => $groupItemIds,
                'piece_id' => $primaryItem->piece_id ?? null,
                'item_name' => $pieceName,
                'service_price' => $servicesTotalPrice,
                'additional_services_total' => (float) ($g['additional_services_total'] ?? 0),
                'quantity' => (int) ($g['quantity'] ?? 1),
                'unit_price' => (float) ($g['unit_price'] ?? 0),
                'total_price' => (float) ($g['total_price'] ?? 0),
                'status' => $g['status'] ?? 'accepted',
                'original_quantity' => $primaryItem->original_quantity ?? $primaryItem->quantity ?? null,
                'original_unit_price' => $originalUnitPrice,
                'original_total_price' => $originalTotalPrice,
                'modified_quantity' => $primaryItem->modified_quantity ?? null,
                'modified_unit_price' => $primaryItem->modified_unit_price !== null ? (float) $primaryItem->modified_unit_price : null,
                'modified_total_price' => $primaryItem->modified_total_price !== null ? (float) $primaryItem->modified_total_price : null,
                'vendor_notes' => $primaryItem->vendor_notes ?? null,
                'note' => $g['note'] ?? null,
                'image' => $g['image'] ?? null,
                'modifiers' => $modifiers,
                'service_additions' => $serviceAdditions,
                'service' => $servicesData[0] ?? null,
                'services' => $servicesData,
                'piece' => $pieceData,
            ];
        });

        $acceptedItems = $items->filter(fn ($i) => ($i['status'] ?? 'accepted') !== 'rejected')->values();
        $rejectedItems = $items->filter(fn ($i) => ($i['status'] ?? 'accepted') === 'rejected')->values();
        // Fully rejected pieces: fold additions into services so mobile shows them on one line.
        $rejectedItems = $rejectedItems->map(fn (array $item) => $this->foldAdditionsIntoServicesForDisplay($item))->values();
        // Group rejected additions under one rejected line per piece (not one line per addition).
        // Skip fully-rejected pieces — their additions already appear on that rejected item.
        $rejectedAdditionItems = $acceptedItems
            ->map(function (array $item) {
                $rejectedAdditions = collect($item['service_additions'] ?? [])
                    ->filter(fn ($addition) => (($addition['vendor_status'] ?? $addition['status'] ?? 'accepted') === 'rejected'))
                    ->values()
                    ->all();

                if ($rejectedAdditions === []) {
                    return null;
                }

                $additionsTotal = (float) collect($rejectedAdditions)->sum(fn ($a) => (float) ($a['total_price'] ?? 0));
                // Mobile renders service_additions as expandable sub-rows; expose rejected additions via services instead.
                $servicesFromRejectedAdditions = collect($rejectedAdditions)
                    ->map(fn (array $addition) => [
                        'id' => $addition['id'],
                        'name' => $addition['name'],
                        'price' => (float) ($addition['price'] ?? 0),
                        'icon' => $addition['icon'] ?? null,
                    ])
                    ->values()
                    ->all();
                $pieceQuantity = (int) ($item['quantity'] ?? 1);

                return [
                    'item_id' => $item['item_id'],
                    'item_ids' => $item['item_ids'] ?? [$item['item_id']],
                    'piece_id' => $item['piece_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'service_price' => 0.0,
                    'additional_services_total' => $additionsTotal,
                    'quantity' => $pieceQuantity,
                    'unit_price' => $additionsTotal,
                    'total_price' => $additionsTotal,
                    'status' => 'rejected',
                    'original_quantity' => $pieceQuantity,
                    'original_unit_price' => $additionsTotal,
                    'original_total_price' => $additionsTotal,
                    'modified_quantity' => null,
                    'modified_unit_price' => null,
                    'modified_total_price' => null,
                    'vendor_notes' => null,
                    'note' => $item['note'] ?? null,
                    'image' => $item['image'] ?? null,
                    'modifiers' => [],
                    'service_additions' => [],
                    'service' => $servicesFromRejectedAdditions[0] ?? null,
                    'services' => $servicesFromRejectedAdditions,
                    'piece' => $item['piece'] ?? null,
                ];
            })
            ->filter()
            ->values();
        $rejectedItems = $rejectedItems->concat($rejectedAdditionItems)->values();

        $acceptedItems = $acceptedItems->map(function (array $item) {
            $acceptedAdditions = collect($item['service_additions'] ?? [])
                ->filter(fn ($addition) => (($addition['vendor_status'] ?? $addition['status'] ?? 'accepted') !== 'rejected'))
                ->values()
                ->all();
            $item['service_additions'] = $acceptedAdditions;
            $item['additional_services_total'] = (float) collect($acceptedAdditions)
                ->sum(fn ($addition) => (float) ($addition['total_price'] ?? 0));

            return $item;
        })->values();

        $subtotal = (float) $order->total_amount;
        $deliveryFee = (float) $order->delivery_fee;
        $discount = (float) $order->discount_amount;
        $tax = (float) $order->tax_amount;
        $finalTotal = (float) $order->final_amount;

        $toBreakdownLine = function (array $i): array {
            $serviceNames = collect($i['services'] ?? [])
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
            $additionNames = collect($i['service_additions'] ?? [])
                ->pluck('name')
                ->filter()
                ->values()
                ->all();

            return [
                'Item_name' => $i['item_name'],
                'name_operation' => $serviceNames !== []
                    ? implode('، ', $serviceNames)
                    : ($additionNames !== []
                        ? implode('، ', $additionNames)
                        : ($i['service']['name'] ?? 'Service')),
                'Quantity' => $i['quantity'],
                'unit_price' => (float) $i['unit_price'],
                'total_price' => (float) $i['total_price'],
                'status' => $i['status'] ?? 'accepted',
                'service_additions' => $i['service_additions'] ?? [],
                'services' => $i['services'] ?? [],
            ];
        };

        $priceBreakdown = [
            'accepted_items' => $acceptedItems->map($toBreakdownLine)->values(),
            'rejected_items' => $rejectedItems->map($toBreakdownLine)->values(),
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'discount' => $discount,
            'tax' => $tax,
            'final_total' => $finalTotal,
        ];

        // User review (only for completed orders)
        $userReview = null;
        if ($order->rating && $order->status === OrderStatus::COMPLETED->value) {
            $userReview = [
                'user_name' => $order->client?->getTranslation('full_name', $locale) ?? 'Anonymous',
                'rating' => $order->rating,
                'comment' => $order->review,
                'date' => $order->updated_at->format('Y-m-d'),
            ];
        }

        // Client info
        $clientInfo = $order->client
            ? $order->client->toApiClientInfo($locale, $uploadService->getFullUrl($order->client->image))
            : null;

        $driverInfo = null;
        if ($order->driver) {
            $driverInfo = [
                'id' => $order->driver->id,
                'name' => $order->driver->getTranslation('full_name', $locale) ?? 'Driver',
                'phone_number' => $order->driver->phone,
                'rating' => (float) ($order->driver->rating ?? 0),
                'image' => $uploadService->getFullUrl($order->driver->image),
            ];
        }

        // Location GPS
        $locationGps = [
            'latitude' => null,
            'longitude' => null,
        ];
        if ($order->pickupAddress) {
            $locationGps = [
                'latitude' => $order->pickupAddress->latitude ? (float) $order->pickupAddress->latitude : null,
                'longitude' => $order->pickupAddress->longitude ? (float) $order->pickupAddress->longitude : null,
            ];
        } elseif ($order->branch && $order->branch->latitude !== null && $order->branch->longitude !== null) {
            $locationGps = [
                'latitude' => (float) $order->branch->latitude,
                'longitude' => (float) $order->branch->longitude,
            ];
        }

        $pickupAddressInfo = $order->pickupAddress ? [
            'id' => $order->pickupAddress->id,
            'address_text' => $order->pickupAddress->address_text ?? $order->pickupAddress->street_name,
            'street_name' => $order->pickupAddress->street_name,
            'building_number' => $order->pickupAddress->building_number ?? null,
            'city' => $order->pickupAddress->city ?? null,
            'district' => $order->pickupAddress->district ?? null,
            'latitude' => (float) $order->pickupAddress->latitude,
            'longitude' => (float) $order->pickupAddress->longitude,
        ] : null;

        $deliveryAddressInfo = $order->deliveryAddress ? [
            'id' => $order->deliveryAddress->id,
            'address_text' => $order->deliveryAddress->address_text ?? $order->deliveryAddress->street_name,
            'street_name' => $order->deliveryAddress->street_name,
            'building_number' => $order->deliveryAddress->building_number ?? null,
            'city' => $order->deliveryAddress->city ?? null,
            'district' => $order->deliveryAddress->district ?? null,
            'latitude' => (float) $order->deliveryAddress->latitude,
            'longitude' => (float) $order->deliveryAddress->longitude,
        ] : null;

        // Payment status
        $paymentStatus = $order->payment_status ?? 'pending';

        // Branch with location
        $branchData = $order->branch?->toApiOrderBranch($locale);

        $handoffService = app(\App\Services\VendorOrderHandoffService::class);
        $handoffActions = $handoffService->availableActions($order);
        $deliveryInfo = $this->buildDeliveryInfo($order, $locale);

        return successResponse(array_merge([
            'id' => $order->id,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'branch_id' => $order->branch_id,
            'order_number' => $order->order_number,
            'total_price' => $subtotal,
            'total_amount' => (float) $order->total_amount,
            'discount_amount' => (float) $order->discount_amount,
            ...$order->couponResponseFields($locale),
            'tax_amount' => (float) $order->tax_amount,
            'delivery_fee' => (float) $order->delivery_fee,
            'final_amount' => (float) $order->final_amount,
            'distance' => $order->distance !== null ? (float) $order->distance : 0,
            'payment_method' => $order->payment_method,
            'payment_status' => $paymentStatus,
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($paymentStatus),
            'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
            'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
            'driver_id' => $order->driver_id,
            'pickup_driver_id' => $order->pickup_driver_id,
            'delivery_driver_id' => $order->delivery_driver_id,
            'branch' => $branchData,
            'client_info' => $clientInfo,
            'Location_gps' => $locationGps,
            'accepted_items' => $acceptedItems->values()->toArray(),
            'rejected_items' => $rejectedItems->values()->toArray(),
            'driver_info' => $driverInfo,
            'pickup_address_id' => $order->pickup_address_id,
            'delivery_address_id' => $order->delivery_address_id,
            'pickup_address' => $pickupAddressInfo,
            'delivery_address' => $deliveryAddressInfo,
            'delivery_info' => $deliveryInfo,
            'rating' => $order->rating !== null ? (int) $order->rating : null,
            'review' => $order->review,
            'user_review' => $userReview,
            'Price_breakdown' => $priceBreakdown,
            'client_chat' => $this->getChatForOrder($order->id, $order->client_id, $vendorId, null),
            'delivery_chat' => $this->getDeliveryChatForOrder($order, $vendorId),
            ...$this->vendorHandoffMeta($order, $handoffService, $handoffActions),
        ], $order->handoffResponseFields()), __('order.order_details_retrieved'));
    }

    private function buildDeliveryInfo(Order $order, string $locale): array
    {
        $pickupAddress = (! $order->pickup_at_vendor && $order->pickupAddress) ? [
            'id' => $order->pickupAddress->id,
            'address' => $order->pickupAddress->address_text ?? $order->pickupAddress->street_name,
            'latitude' => $order->pickupAddress->latitude ? (float) $order->pickupAddress->latitude : null,
            'longitude' => $order->pickupAddress->longitude ? (float) $order->pickupAddress->longitude : null,
        ] : null;

        $deliveryAddress = (! $order->delivery_at_vendor && $order->deliveryAddress) ? [
            'id' => $order->deliveryAddress->id,
            'address' => $order->deliveryAddress->address_text ?? $order->deliveryAddress->street_name,
            'latitude' => $order->deliveryAddress->latitude ? (float) $order->deliveryAddress->latitude : null,
            'longitude' => $order->deliveryAddress->longitude ? (float) $order->deliveryAddress->longitude : null,
        ] : null;

        $activeDriver = $this->resolveActiveDriverForOrder($order);
        $currentLocation = null;
        if ($activeDriver && $activeDriver->latitude && $activeDriver->longitude) {
            $currentLocation = [
                'driver_id' => $activeDriver->id,
                'latitude' => (float) $activeDriver->latitude,
                'longitude' => (float) $activeDriver->longitude,
            ];
        }

        return [
            'pickup_address' => $pickupAddress,
            'delivery_address' => $deliveryAddress,
            'branch_location' => $order->branch?->getApiLocation($locale),
            'current_location' => $currentLocation,
        ];
    }

    private function resolveActiveDriverForOrder(Order $order): ?Driver
    {
        $orderStatus = OrderStatus::fromString($order->status);

        if ($orderStatus && in_array($orderStatus, [
            OrderStatus::DRIVER_PICKUP_ASSIGNED,
            OrderStatus::DRIVER_PICKUP_ACCEPTED,
            OrderStatus::ON_WAY_TO_PICKUP,
            OrderStatus::PICKED_UP,
            OrderStatus::DELIVERED_TO_BRANCH,
        ], true)) {
            return $order->pickupDriver ?? $order->driver;
        }

        if ($orderStatus && in_array($orderStatus, [
            OrderStatus::DRIVER_DELIVERY_ASSIGNED,
            OrderStatus::DRIVER_DELIVERY_ACCEPTED,
            OrderStatus::ON_WAY_TO_DELIVERY,
            OrderStatus::WAITING_CLIENT_RECEIPT,
            OrderStatus::DELIVERED,
        ], true)) {
            return $order->deliveryDriver ?? $order->driver;
        }

        return $order->driver;
    }

    private function getChatForOrder(int $orderId, ?int $clientId = null, ?int $vendorId = null, ?int $driverId = null): array
    {
        $conversation = $this->chatService->getConversationForOrder($orderId, $clientId, $vendorId, $driverId);

        return [
            'conversation_id' => $conversation?->id,
            'order_id' => $conversation?->order_id ?? $orderId,
        ];
    }

    private function getDeliveryChatForOrder($order, int $vendorId): array
    {
        $driverId = $order->delivery_driver_id ?? $order->pickup_driver_id ?? $order->driver_id;

        if (! $driverId) {
            return ['conversation_id' => null, 'order_id' => $order->id];
        }

        $conversation = $this->chatService->getConversationForOrder($order->id, (int) $order->client_id, $vendorId, (int) $driverId);

        return [
            'conversation_id' => $conversation?->id,
            'order_id' => $conversation?->order_id ?? $order->id,
        ];
    }

    private function vendorHandoffMeta(
        Order $order,
        \App\Services\VendorOrderHandoffService $handoffService,
        ?array $handoffActions = null
    ): array {
        $handoffActions ??= $handoffService->availableActions($order);

        return array_merge([
            'requires_handoff_action' => $handoffActions !== [],
            'available_handoff_actions' => $handoffActions,
        ], $handoffService->vendorConfirmFlags($order));
    }

    public function confirmHandoff(Request $request, $orderId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = $this->branchService->getBranchesByVendor($vendorId)->pluck('id')->toArray();

        $validator = Validator::make($request->all(), [
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $order = Order::where('id', $orderId)
            ->whereIn('branch_id', $branchIds)
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $handoffService = app(\App\Services\VendorOrderHandoffService::class);

        try {
            $message = __('order.vendor_handoff_not_available');

            if ($handoffService->canConfirmPickupFromDriver($order)) {
                $order = $handoffService->confirmPickupFromDriver($order, (int) $employee->id, $request->notes);
                $message = __('order.vendor_handoff_success_pickup_from_driver');
            } elseif ($handoffService->canConfirmHandoverToDelivery($order)) {
                $order = $handoffService->confirmHandoverToDelivery($order, (int) $employee->id, $request->notes);
                $message = __('order.vendor_handoff_success_handover_to_delivery');
            } else {
                return errorResponse(__('order.vendor_handoff_not_available'), null, 400);
            }
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse($e->userMessage(), null, 400);
        } catch (\LogicException $e) {
            return errorResponse($e->getMessage() ?: __('order.vendor_handoff_not_available'), null, 400);
        }

        $order = $order->fresh();
        $handoffActions = $handoffService->availableActions($order);

        return successResponse(array_merge([
            'id' => $order->id,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
            'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
            ...$this->vendorHandoffMeta($order, $handoffService, $handoffActions),
        ], $order->handoffResponseFields()), $message);
    }

    public function updateStatus(Request $request, $orderId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branches = $this->branchService->getBranchesByVendor($vendorId);
        $branchIds = $branches->pluck('id')->toArray();

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'in:delivered_to_branch,delivered,picked_up,completed,cancelled'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $order = $this->orderService->getOrderById((int) $orderId);

        if (! $order || ! in_array($order->branch_id, $branchIds)) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $handoffService = app(\App\Services\VendorOrderHandoffService::class);
        $statusService = app(\App\Services\OrderStatusService::class);
        $targetStatus = OrderStatus::from($request->status);

        try {
            if ($targetStatus === OrderStatus::DELIVERED_TO_BRANCH && $handoffService->canConfirmPickupReceived($order)) {
                $order = $handoffService->confirmPickupReceived($order, (int) $employee->id, $request->notes);
            } elseif (
                $targetStatus === OrderStatus::COMPLETED
                && (bool) $order->delivery_at_vendor
                && $handoffService->canRequestClientDelivery($order)
            ) {
                $order = $handoffService->requestClientDelivery($order, (int) $employee->id, $request->notes);
            } elseif (
                $targetStatus === OrderStatus::DELIVERED
                && (bool) $order->delivery_at_vendor
                && $handoffService->canConfirmClientPickupReceived($order)
            ) {
                $order = $handoffService->confirmClientPickupReceived($order, (int) $employee->id, $request->notes);
            } elseif (
                $targetStatus === OrderStatus::DELIVERED
                && (bool) $order->delivery_at_vendor
            ) {
                return errorResponse(__('order.vendor_branch_pickup_use_completed_not_delivered'), null, 400);
            } else {
                $statusService->transitionTo($order, $targetStatus, [
                    'notes' => $request->notes,
                    'changed_by' => $employee->id,
                ]);
            }
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse($e->userMessage(), null, 400);
        } catch (\LogicException $e) {
            return errorResponse($e->getMessage() ?: __('order.vendor_handoff_not_available'), null, 400);
        }

        $order = $order->fresh();

        return successResponse(array_merge([
            'id' => $order->id,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
            'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
            ...$this->vendorHandoffMeta($order, $handoffService),
        ], $order->handoffResponseFields()), __('order.order_status_updated'));
    }

    public function accept(Request $request, $orderId): JsonResponse
    {
        return $this->acceptOrder($request, $orderId);
    }

    public function reject(Request $request, $orderId): JsonResponse
    {
        return $this->rejectOrder($request, $orderId);
    }

    public function acceptOrder(Request $request, $orderId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branches = $this->branchService->getBranchesByVendor($vendorId);
        $branchIds = $branches->pluck('id')->toArray();

        $order = $this->orderService->getOrderWithRelations((int) $orderId, ['items']);

        if (! $order || ! in_array($order->branch_id, $branchIds)) {
            return notFoundResponse(__('order.order_not_found'));
        }

        if ($order->status !== OrderStatus::PENDING->value) {
            return errorResponse(__('order.order_must_be_pending'), null, 400);
        }

        $itemsReview = $order->items->map(fn ($item) => ['item_id' => $item->id, 'status' => 'accepted'])->toArray();

        $reviewService = app(\App\Services\VendorOrderReviewService::class);
        $result = $reviewService->reviewOrderItems($order, $itemsReview, $request->notes);

        if ($result['success']) {
            return successResponse(
                $this->formatVendorOrderWithGroupedItems($result['order']),
                __('order.order_accepted_successfully')
            );
        }

        return errorResponse($result['message'], null, 400);
    }

    public function rejectOrder(Request $request, $orderId): JsonResponse
    {
        $validator = Validator::make($request->all(), ['reason' => ['required', 'string', 'max:500']]);
        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branches = $this->branchService->getBranchesByVendor($vendorId);
        $branchIds = $branches->pluck('id')->toArray();

        $order = $this->orderService->getOrderById((int) $orderId);

        if (! $order || ! in_array($order->branch_id, $branchIds)) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $statusService = app(\App\Services\OrderStatusService::class);

        try {
            $statusService->transitionTo($order, OrderStatus::CANCELLED, [
                'notes' => __('order.vendor_log_order_rejected', ['reason' => $request->reason]),
                'changed_by' => $employee->id,
            ]);
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse(__('order.order_cannot_be_rejected_current_status'), null, 400);
        }

        return successResponse($order->fresh(), __('order.order_rejected_successfully'));
    }

    /**
     * Get order status log
     */
    public function getStatusLog(Request $request, $orderId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branches = $this->branchService->getBranchesByVendor($vendorId);
        $branchIds = $branches->pluck('id')->toArray();

        $order = Order::where('id', $orderId)
            ->whereIn('branch_id', $branchIds)
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $statusLogs = $order->statusLogs()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($log) => OrderStatusLogPresenter::forVendor($log, $order));

        return successResponse($statusLogs, __('order.status_log_retrieved'));
    }

    /**
     * Review order items (accept/reject/modify)
     */
    public function reviewOrder(Request $request, $orderId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'exists:order_items,id'],
            'items.*.status' => ['required', 'in:accepted,rejected,modified'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required_if:items.*.status,modified', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_if:items.*.status,modified', 'numeric', 'min:0'],
            'items.*.additional_services' => ['nullable', 'array'],
            'items.*.additional_services.*.service_addition_id' => ['required', 'exists:order_item_additional_services,service_addition_id'],
            'items.*.additional_services.*.status' => ['required', 'in:accepted,rejected'],
            'items.*.additional_services.*.notes' => ['nullable', 'string', 'max:500'],
            'general_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branches = $this->branchService->getBranchesByVendor($vendorId);
        $branchIds = $branches->pluck('id')->toArray();

        $order = Order::whereIn('branch_id', $branchIds)->find($orderId);

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $reviewService = app(\App\Services\VendorOrderReviewService::class);
        $result = $reviewService->reviewOrderItems(
            $order,
            $request->items,
            $request->general_notes
        );

        if ($result['success']) {
            return successResponse(
                $this->formatVendorOrderWithGroupedItems($result['order']),
                $result['message']
            );
        }

        return errorResponse($result['message'], null, 400);
    }

    /**
     * Shape vendor review/accept payloads so multi-service pieces stay one line.
     */
    private function formatVendorOrderWithGroupedItems(Order $order): array
    {
        $order->loadMissing([
            'items.piece',
            'items.service',
            'items.additionalServicesPivot.serviceAddition',
            'client',
            'branch',
            'driver',
        ]);

        $lang = app()->getLocale();
        $branchId = (int) ($order->branch_id ?? 0);
        $data = $order->toArray();
        $data['items'] = \Modules\Order\Support\OrderItemGrouper::toApiLines(
            $order->items,
            $branchId,
            $lang
        );

        return $data;
    }
}
