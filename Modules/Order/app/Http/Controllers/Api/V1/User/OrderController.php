<?php

namespace Modules\Order\Http\Controllers\Api\V1\User;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\FirebaseService;
use App\Services\OrderCatalogAvailabilityService;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Address\Models\Address;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\AdminSetting;
use Modules\Discount\Models\Discount;
use Modules\Discount\Services\DiscountService;
use Modules\Order\Exceptions\InsufficientWalletBalanceException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderItemAdditionalService;
use Modules\Order\Models\OrderStatusLog;
use Modules\Order\Models\PendingOrder;
use Modules\Order\Services\OrderPaymentService;
use Modules\Order\Support\OrderItemGrouper;
use Modules\Order\Support\OrderItemsNormalizer;
use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentService;
use Modules\Piece\Models\Piece;
use Modules\Vendor\Models\Vendor;

class OrderController extends Controller
{
    protected $uploadFilesService;

    protected $discountService;

    protected $paymentService;

    protected $firebaseService;

    protected $catalogAvailabilityService;

    protected OrderPaymentService $orderPaymentService;

    public function __construct(
        UploadFilesService $uploadFilesService,
        DiscountService $discountService,
        PaymentService $paymentService,
        FirebaseService $firebaseService,
        OrderCatalogAvailabilityService $catalogAvailabilityService,
        OrderPaymentService $orderPaymentService
    ) {
        $this->uploadFilesService = $uploadFilesService;
        $this->discountService = $discountService;
        $this->paymentService = $paymentService;
        $this->firebaseService = $firebaseService;
        $this->catalogAvailabilityService = $catalogAvailabilityService;
        $this->orderPaymentService = $orderPaymentService;
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     * Returns distance in kilometers
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Build a single order line for API responses (calculate, store, etc.).
     * Piece has no standalone price; service.price is from service_piece (piece + service at branch).
     *
     * @param  list<array{id:int,service_id?:int,name:string,service_name:string,price:float}>|null  $services
     */
    private function formatOrderLineItem(
        $piece,
        $service,
        float $servicePiecePrice,
        array $additionalServices,
        float $additionalServicesTotal,
        int $quantity,
        float $unitPrice,
        string $lang,
        ?int $branchId = null,
        ?int $orderItemId = null,
        ?string $note = null,
        ?string $image = null,
        ?float $lineTotalPrice = null,
        ?array $services = null
    ): array {
        $serviceLabel = $branchId
            ? \App\Support\OrderItemDisplayNames::serviceName($service, $branchId, $lang)
            : (method_exists($service, 'getTranslation')
                ? $service->getTranslation('service_name', $lang)
                : ($service->service_name ?? ''));

        $primaryService = [
            'id' => $service->id,
            'service_id' => $service->id,
            'name' => $serviceLabel,
            'service_name' => $serviceLabel,
            'price' => (float) $servicePiecePrice,
        ];

        $servicesList = $services ?? [$primaryService];

        $line = [
            'piece' => [
                'id' => $piece->id,
                'name' => $branchId
                    ? \App\Support\OrderItemDisplayNames::pieceName($piece, $branchId, $lang)
                    : (method_exists($piece, 'getTranslation')
                        ? $piece->getTranslation('name', $lang)
                        : $piece->name),
                'icon' => $piece->iconRelation?->full_path,
            ],
            'service' => $servicesList[0] ?? $primaryService,
            'services' => $servicesList,
            'additional_services' => $additionalServices,
            'additional_services_total' => (float) $additionalServicesTotal,
            'quantity' => $quantity,
            'unit_price' => (float) $unitPrice,
            'total_price' => (float) ($lineTotalPrice ?? ($unitPrice * $quantity)),
        ];

        if ($orderItemId !== null) {
            $line['id'] = $orderItemId;
        }
        if ($note !== null) {
            $line['note'] = $note;
        }
        if ($image !== null) {
            $line['image'] = $image;
        }

        return $line;
    }

    /**
     * Resolve pickup/delivery addresses for order calculation (calculate, validate-coupon).
     *
     * @return array{pickupAddress: ?Address, deliveryAddress: ?Address}|JsonResponse
     */
    private function resolveOrderAddressesForCalculation(
        Request $request,
        $user,
        $branch,
        bool $pickupAtVendor,
        bool $deliveryAtVendor
    ): array|JsonResponse {
        $pickupAddress = null;
        $deliveryAddress = null;

        if (! $pickupAtVendor) {
            if ($request->pickup_address_id) {
                $pickupAddress = Address::where('id', $request->pickup_address_id)
                    ->where('client_id', $user->id)
                    ->first();

                if (! $pickupAddress) {
                    return errorResponse(__('order.invalid_pickup_address'), 400);
                }
            } else {
                $pickupAddress = $user->defaultAddress();
                if (! $pickupAddress) {
                    return errorResponse(__('order.no_pickup_address'), 400);
                }
            }
        }

        if (! $deliveryAtVendor) {
            if ($request->delivery_address_id) {
                $deliveryAddress = Address::where('id', $request->delivery_address_id)
                    ->where('client_id', $user->id)
                    ->first();

                if (! $deliveryAddress) {
                    return errorResponse(__('order.invalid_delivery_address'), 400);
                }
            } else {
                $deliveryAddress = $user->defaultAddress();
                if (! $deliveryAddress) {
                    return errorResponse(__('order.no_delivery_address'), 400);
                }
            }
        }

        $branchZoneId = $branch->zone_id;
        if ($branchZoneId !== null) {
            if ($pickupAddress) {
                if ($pickupAddress->zone_id === null) {
                    return errorResponse(__('order.address_must_be_in_branch_zone'), 400);
                }
                if ((int) $pickupAddress->zone_id !== (int) $branchZoneId) {
                    return errorResponse(__('order.pickup_address_not_in_branch_zone'), 400);
                }
            }
            if ($deliveryAddress) {
                if ($deliveryAddress->zone_id === null) {
                    return errorResponse(__('order.address_must_be_in_branch_zone'), 400);
                }
                if ((int) $deliveryAddress->zone_id !== (int) $branchZoneId) {
                    return errorResponse(__('order.delivery_address_not_in_branch_zone'), 400);
                }
            }
        }

        return compact('pickupAddress', 'deliveryAddress');
    }

    /**
     * Compute pickup/delivery fees and distance for order calculation.
     *
     * @return array{
     *     delivery_fee: float,
     *     pickup_fee: float,
     *     delivery_fee_amount: float,
     *     total_distance_km: float,
     *     distance: float
     * }|JsonResponse
     */
    private function computeDeliveryFees(
        bool $pickupAtVendor,
        bool $deliveryAtVendor,
        $branch,
        $vendor,
        ?Address $pickupAddress,
        ?Address $deliveryAddress
    ): array|JsonResponse {
        $deliveryFee = 0.0;
        $totalDistance = 0.0;
        $pickupFee = 0.0;
        $deliveryFeeAmount = 0.0;

        if ($pickupAtVendor && $deliveryAtVendor) {
            return [
                'delivery_fee' => $deliveryFee,
                'pickup_fee' => $pickupFee,
                'delivery_fee_amount' => $deliveryFeeAmount,
                'total_distance_km' => round($totalDistance, 2),
                'distance' => round($totalDistance, 2),
            ];
        }

        if (! $branch->latitude || ! $branch->longitude) {
            return errorResponse(__('order.vendor_location_not_available'), 400);
        }

        $deliveryPricePerKm = $vendor->delivery_price_per_km
            ?? AdminSetting::getValue('delivery_price_per_km', 5);

        if (! $pickupAtVendor && $pickupAddress) {
            if (! $pickupAddress->latitude || ! $pickupAddress->longitude) {
                return errorResponse(__('order.pickup_address_location_not_available'), 400);
            }
            $pickupDistance = $this->calculateDistance(
                (float) $pickupAddress->latitude,
                (float) $pickupAddress->longitude,
                (float) $branch->latitude,
                (float) $branch->longitude
            );
            $totalDistance += $pickupDistance;
            $pickupFee = $pickupDistance * $deliveryPricePerKm;
        }

        if (! $deliveryAtVendor && $deliveryAddress) {
            if (! $deliveryAddress->latitude || ! $deliveryAddress->longitude) {
                return errorResponse(__('order.delivery_address_location_not_available'), 400);
            }
            $deliveryDistance = $this->calculateDistance(
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
            'delivery_fee' => (float) $deliveryFee,
            'pickup_fee' => (float) $pickupFee,
            'delivery_fee_amount' => (float) $deliveryFeeAmount,
            'total_distance_km' => (float) round($totalDistance, 2),
            'distance' => (float) round($totalDistance, 2),
        ];
    }

    /**
     * Build pricing summary fields shared by calculate and validate-coupon.
     */
    private function buildOrderPricingSummary(
        float $totalAmount,
        float $discountAmount,
        float $deliveryDiscountAmount,
        array $deliveryFees,
        bool $pickupAtVendor,
        bool $deliveryAtVendor
    ): array {
        $totals = Order::calculatePricingTotals(
            $totalAmount,
            $discountAmount,
            (float) $deliveryFees['delivery_fee'],
            $deliveryDiscountAmount
        );

        $summary = [
            'subtotal' => (float) $totals['subtotal'],
            'discount_amount' => (float) $totals['discount_amount'],
            'delivery_discount_amount' => (float) ($totals['delivery_discount_amount'] ?? 0),
            'subtotal_after_discount' => (float) $totals['subtotal_after_discount'],
            'tax_percentage' => (float) $totals['tax_percentage'],
            'tax_amount' => (float) $totals['tax_amount'],
            'delivery_fee' => (float) $totals['delivery_fee'],
            'final_amount' => (float) $totals['final_amount'],
            'pickup_fee' => (float) $deliveryFees['pickup_fee'],
            'delivery_fee_amount' => (float) $deliveryFees['delivery_fee_amount'],
            'total_distance_km' => (float) $deliveryFees['total_distance_km'],
            'distance' => (float) $deliveryFees['distance'],
            'pickup_at_vendor' => $pickupAtVendor,
            'delivery_at_vendor' => $deliveryAtVendor,
        ];

        if ((float) $totals['delivery_fee'] == 0.0) {
            $summary['is_free_delivery'] = true;
        }

        return $summary;
    }

    /**
     * Calculate order summary without creating the order
     */
    public function calculate(Request $request): JsonResponse
    {
        $request->merge([
            'items' => OrderItemsNormalizer::normalize($request->input('items', [])),
        ]);

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'exists:branches,id'],
            'pickup_at_vendor' => ['required', 'boolean'],
            'delivery_at_vendor' => ['required', 'boolean'],
            'pickup_address_id' => [
                Rule::requiredIf(fn () => ! $request->boolean('pickup_at_vendor')),
                'nullable',
                'exists:addresses,id',
            ],
            'delivery_address_id' => [
                Rule::requiredIf(fn () => ! $request->boolean('delivery_at_vendor')),
                'nullable',
                'exists:addresses,id',
            ],
            'coupon_code' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.piece_id' => ['required', 'exists:pieces,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.service_id' => ['required', 'exists:services,id'],
            'items.*.additional_service_ids' => ['nullable', 'array'],
            'items.*.additional_service_ids.*' => ['integer', 'exists:service_additions,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        // Get language from current locale (set by middleware)
        $lang = app()->getLocale();

        // Get branch and vendor
        $branch = \Modules\Branch\Models\Branch::with('vendor')->find($request->branch_id);
        if (! $branch) {
            return errorResponse(__('branch.not_found'), 404);
        }

        if (! $branch->is_active) {
            return errorResponse(__('branch.not_active'), 400);
        }

        $vendor = $branch->vendor;
        if (! $vendor) {
            return errorResponse(__('order.vendor_not_found'), 404);
        }

        if (! $vendor->is_active) {
            return errorResponse(__('order.vendor_not_active'), 400);
        }

        if ($vendor->is_banned) {
            return errorResponse(__('order.vendor_banned'), 403);
        }

        $vendorId = $vendor->id;

        try {
            $pickupAtVendor = $request->boolean('pickup_at_vendor');
            $deliveryAtVendor = $request->boolean('delivery_at_vendor');

            $addresses = $this->resolveOrderAddressesForCalculation(
                $request,
                $user,
                $branch,
                $pickupAtVendor,
                $deliveryAtVendor
            );
            if ($addresses instanceof JsonResponse) {
                return $addresses;
            }
            $pickupAddress = $addresses['pickupAddress'];
            $deliveryAddress = $addresses['deliveryAddress'];

            // Calculate items total and validate
            $discountAmount = 0;
            $deliveryDiscountAmount = 0;
            $appliedDiscount = null;
            $couponSuccessMessage = null;
            $totalAmount = 0;
            $itemsSummary = [];
            $pieces = null;
            $discountItemsBreakdown = [];

            if ($request->has('coupon_code') && $request->coupon_code) {
                $result = $this->discountService->validateAndCalculateDiscount(
                    $request->coupon_code,
                    $request->items,
                    $user->id,
                    $vendorId,
                    $lang,
                    (int) $request->branch_id
                );

                if (! $result['success']) {
                    return errorResponse($result['message'], $result['code'], $result['errors'] ?? null);
                }

                $totalAmount = $result['data']['order_amount'];
                $discountAmount = $result['data']['discount_amount'];
                $deliveryDiscountAmount = (float) ($result['data']['delivery_discount_amount'] ?? 0);
                $appliedDiscount = $result['data']['discount'];
                $pieces = $result['data']['pieces'];
                $discountItemsBreakdown = $result['data']['items_breakdown'] ?? [];
                $couponSuccessMessage = $result['message'];
            } else {
                $pieceIds = collect($request->items)->pluck('piece_id')->unique();
                $pieces = Piece::with([
                    'vendor',
                    'services',
                    'additionalServices' => function ($query) use ($request) {
                        $query->where(function ($q) use ($request) {
                            $q->where('service_addition_piece.branch_id', $request->branch_id)
                                ->orWhereNull('service_addition_piece.branch_id');
                        });
                    },
                ])
                    ->whereIn('id', $pieceIds)
                    ->get();

                if ($pieces->count() !== $pieceIds->count()) {
                    return errorResponse(__('order.items_not_available'), 400);
                }

                $vendorIds = $pieces->pluck('vendor_id')->unique();
                if ($vendorIds->count() > 1) {
                    return errorResponse(__('order.items_same_vendor'), 400);
                }

                $itemsVendorId = $vendorIds->first();
                if ($itemsVendorId != $vendorId) {
                    return errorResponse(__('order.items_vendor_not_match'), 400);
                }
            }

            $branchId = $request->branch_id;

            // Calculate item totals (one cart line may include multiple main services)
            foreach ($request->items as $item) {
                $mainServiceIds = OrderItemsNormalizer::mainServiceIds($item);
                if ($mainServiceIds === []) {
                    return errorResponse(__('order.service_not_available', ['piece_name' => '']), 400);
                }

                $piece = $pieces->firstWhere('id', $item['piece_id']);
                $servicesSummary = [];
                $servicesTotal = 0.0;
                $primaryService = null;
                $primaryServicePrice = 0.0;

                foreach ($mainServiceIds as $mainServiceId) {
                    $availabilityError = $this->catalogAvailabilityService->validateOrderLineForNewOrder(
                        (int) $branchId,
                        (int) $item['piece_id'],
                        (int) $mainServiceId,
                        $item['additional_service_ids'] ?? [],
                        $lang
                    );
                    if ($availabilityError !== null) {
                        return errorResponse($availabilityError, 400);
                    }

                    $service = $piece->services->firstWhere('id', $mainServiceId);
                    if (! $service) {
                        $pieceName = \App\Support\OrderItemDisplayNames::pieceName($piece, (int) $branchId, $lang);

                        return errorResponse(__('order.service_not_available', ['piece_name' => $pieceName]), 400);
                    }

                    $servicePiecePrice = (float) $service->getPriceForPieceAtBranch($piece->id, $branchId);
                    $servicesTotal += $servicePiecePrice;
                    $serviceLabel = \App\Support\OrderItemDisplayNames::serviceName($service, (int) $branchId, $lang);
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
                    $uniqueCalcAdditionalServiceIds = array_unique($item['additional_service_ids']);
                    foreach ($uniqueCalcAdditionalServiceIds as $additionalServiceId) {
                        $additionModel = \Modules\Service\Models\ServiceAddition::find($additionalServiceId);
                        if ($additionModel) {
                            $additionalPrice = (float) $additionModel->getPriceForPieceAtBranch($piece->id, $branchId);
                            $additionalServicesTotal += $additionalPrice;
                            $additionalServicesSummary[] = \App\Support\OrderItemDisplayNames::additionalServiceLine(
                                $additionModel,
                                (int) $branchId,
                                $lang,
                                $additionalPrice
                            );
                        }
                    }
                }

                $unitPrice = $servicesTotal + $additionalServicesTotal;
                $itemTotal = $unitPrice * $item['quantity'];

                if (! $appliedDiscount) {
                    $totalAmount += $itemTotal;
                }

                $quantity = (int) $item['quantity'];
                for ($i = 0; $i < $quantity; $i++) {
                    $itemsSummary[] = $this->formatOrderLineItem(
                        $piece,
                        $primaryService,
                        $primaryServicePrice,
                        $additionalServicesSummary,
                        $additionalServicesTotal,
                        1,
                        $unitPrice,
                        $lang,
                        (int) $branchId,
                        null,
                        null,
                        null,
                        null,
                        $servicesSummary
                    );
                }
            }

            $deliveryFees = $this->computeDeliveryFees(
                $pickupAtVendor,
                $deliveryAtVendor,
                $branch,
                $vendor,
                $pickupAddress,
                $deliveryAddress
            );
            if ($deliveryFees instanceof JsonResponse) {
                return $deliveryFees;
            }

            $discountCity = $deliveryAddress?->city ?? $pickupAddress?->city;
            if ($appliedDiscount && $discountItemsBreakdown !== []) {
                $rechecked = $this->discountService->evaluateKnownOrderDiscount(
                    $appliedDiscount,
                    $discountItemsBreakdown,
                    (float) $totalAmount,
                    (int) $user->id,
                    (int) $vendorId,
                    (int) $branchId,
                    (float) $deliveryFees['delivery_fee'],
                    $discountCity,
                    false,
                    $lang
                );
                $discountAmount = (float) $rechecked['discount_amount'];
                $deliveryDiscountAmount = (float) ($rechecked['delivery_discount_amount'] ?? 0);
            } elseif (! $request->filled('coupon_code')) {
                $automatic = $this->discountService->findBestAutomaticOrderDiscount(
                    $request->items,
                    (int) $user->id,
                    (int) $vendorId,
                    $lang,
                    (int) $branchId,
                    (float) $deliveryFees['delivery_fee'],
                    $discountCity
                );
                if ($automatic['applied']) {
                    $appliedDiscount = $automatic['discount'];
                    $discountAmount = (float) $automatic['discount_amount'];
                    $deliveryDiscountAmount = (float) ($automatic['delivery_discount_amount'] ?? 0);
                }
            }

            $pricingSummary = $this->buildOrderPricingSummary(
                (float) $totalAmount,
                (float) $discountAmount,
                (float) $deliveryDiscountAmount,
                $deliveryFees,
                $pickupAtVendor,
                $deliveryAtVendor
            );

            return successResponse([
                'have_coupon' => $request->filled('coupon_code') && (bool) $appliedDiscount,
                'summary' => array_merge([
                    'items' => $itemsSummary,
                    'items_count' => count($itemsSummary),
                ], $pricingSummary),
                'delivery_info' => [
                    'pickup_address' => $pickupAddress ? [
                        'id' => $pickupAddress->id,
                        'address' => $pickupAddress->address_text ?? $pickupAddress->street_name,
                        'latitude' => $pickupAddress->latitude ? (float) $pickupAddress->latitude : null,
                        'longitude' => $pickupAddress->longitude ? (float) $pickupAddress->longitude : null,
                    ] : null,
                    'delivery_address' => $deliveryAddress ? [
                        'id' => $deliveryAddress->id,
                        'address' => $deliveryAddress->address_text ?? $deliveryAddress->street_name,
                        'latitude' => $deliveryAddress->latitude ? (float) $deliveryAddress->latitude : null,
                        'longitude' => $deliveryAddress->longitude ? (float) $deliveryAddress->longitude : null,
                    ] : null,
                    'branch_location' => $branch->getApiLocation($lang),
                ],
                'discount' => ($request->filled('coupon_code') && $appliedDiscount) ? [
                    'code' => $appliedDiscount->code,
                    'name' => $appliedDiscount->name,
                    'type' => $appliedDiscount->type,
                    'value' => (float) $appliedDiscount->value,
                    'discount_amount' => round((float) $discountAmount + (float) $deliveryDiscountAmount, 2),
                    'delivery_discount_amount' => (float) $deliveryDiscountAmount,
                    'is_automatic' => (bool) ($appliedDiscount->is_automatic ?? false),
                ] : null,
            ], $couponSuccessMessage ?? __('order.order_calculation_completed'));

        } catch (\Exception $e) {
            return serverErrorResponse(__('order.failed_to_calculate').': '.$e->getMessage());
        }
    }

    /**
     * Get all orders (with status filter)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get all valid order status values
        $validStatuses = array_map(fn ($s) => $s->value, OrderStatus::cases());
        $validator = Validator::make($request->all(), [
            'status' => ['nullable', 'string', 'in:current,completed,cancelled,'.implode(',', $validStatuses)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $status = $request->query('status');

        $query = Order::with(['vendor', 'branch.vendor', 'latestPayment', 'discount', 'items.piece', 'items.service', 'items.additionalServicesPivot.serviceAddition'])
            ->where('client_id', $user->id);

        // Handle status filtering
        if ($status) {
            switch ($status) {
                case 'current':
                    // delivered still counts as "current" — the client has the order,
                    // but it's not administratively closed until completed. Only
                    // cancelled/completed leave the current tab.
                    $query->whereNotIn('status', [
                        OrderStatus::CANCELLED->value,
                        OrderStatus::COMPLETED->value,
                    ]);
                    break;
                case 'completed':
                    $query->where('status', OrderStatus::COMPLETED->value);
                    break;
                case 'cancelled':
                    $query->where('status', OrderStatus::CANCELLED->value);
                    break;
                default:
                    if (in_array($status, $validStatuses)) {
                        $query->where('status', $status);
                    }
                    break;
            }
        }

        $orders = $query->orderBy('updated_at', 'desc')
            ->paginate($request->per_page ?? 15);

        // Get language from current locale (set by middleware)
        $lang = app()->getLocale();
        $userLat = $request->query('latitude');
        $userLon = $request->query('longitude');

        $orders->getCollection()->transform(function ($order) use ($lang) {
            $branchData = $order->branch
                ? $order->branch->toApiOrderBranchFlat($lang, [
                    'delivery_price_per_km' => (float) (
                        $order->branch->vendor?->delivery_price_per_km
                        ?? $order->vendor?->delivery_price_per_km
                        ?? 0
                    ),
                ])
                : null;

            // Calculate time remaining until pickup
            $timeRemaining = null;
            if ($order->pickup_time) {
                $now = now();
                $pickupTime = \Carbon\Carbon::parse($order->pickup_time);

                if ($pickupTime->isFuture()) {
                    $diff = $now->diff($pickupTime);

                    // Format time remaining
                    $parts = [];
                    if ($diff->d > 0) {
                        $parts[] = $diff->d.' '.($lang === 'ar' ? ($diff->d == 1 ? 'يوم' : 'أيام') : ($diff->d == 1 ? 'day' : 'days'));
                    }
                    if ($diff->h > 0) {
                        $parts[] = $diff->h.' '.($lang === 'ar' ? ($diff->h == 1 ? 'ساعة' : 'ساعات') : ($diff->h == 1 ? 'hour' : 'hours'));
                    }
                    if ($diff->i > 0 && $diff->d == 0) {
                        $parts[] = $diff->i.' '.($lang === 'ar' ? ($diff->i == 1 ? 'دقيقة' : 'دقائق') : ($diff->i == 1 ? 'minute' : 'minutes'));
                    }

                    $timeRemaining = ! empty($parts) ? implode(' '.($lang === 'ar' ? 'و' : 'and').' ', $parts) : null;
                }
            }

            // First item image
            $firstItemImage = null;
            $itemWithImage = $order->items->first(fn ($item) => ! empty($item->images));
            if ($itemWithImage) {
                $firstItemImage = $this->uploadFilesService->getFullUrl($itemWithImage->images);
            }

            // Branch location (latitude, longitude, address)
            $branchLocation = $order->branch?->getApiLocation($lang);

            return array_merge([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_label' => OrderStatus::fromString($order->status)?->localizedLabel($order->payment_method, $order->status === OrderStatus::COMPLETED->value && ! $order->client_delivery_handoff_at, (bool) $order->delivery_at_vendor) ?? $order->status,
                'laundry' => $order->vendor ? [
                    'id' => $order->vendor->id,
                    'name' => $order->vendor->getTranslatedName($lang),
                    'logo' => $this->uploadFilesService->getFullUrl($order->vendor->logo),
                ] : null,
                'branch' => $branchData,
                'first_item_image' => $firstItemImage,
                'branch_location' => $branchLocation,
                'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                'total_amount' => (float) $order->total_amount,
                'discount_amount' => (float) $order->discount_amount,
                ...$order->couponResponseFields($lang),
                'tax_amount' => (float) $order->tax_amount,
                'delivery_fee' => (float) $order->delivery_fee,
                'final_amount' => (float) $order->final_amount,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status ?? 'pending',
                'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
                'items_count' => \Modules\Order\Support\OrderItemGrouper::totalPiecesCount($order->items),
                'distance' => $order->distance !== null ? (float) $order->distance : 0,
                'pickup_time' => $order->pickup_time ? $order->pickup_time->toISOString() : null,
                'time_remaining' => $timeRemaining,
                'rating' => $order->rating !== null ? (int) $order->rating : null,
                'review' => $order->review,
                'created_at' => $order->created_at->toISOString(),
            ], $order->clientVisitResponseFields());
        });

        // Get translated message using trans() with explicit locale
        $message = __('order.orders_retrieved');

        return successResponse($orders, $message);
    }

    /**
     * Create new order
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'items' => OrderItemsNormalizer::normalize($request->input('items', [])),
        ]);

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'exists:branches,id'],
            'pickup_at_vendor' => ['required', 'boolean'],
            'delivery_at_vendor' => ['required', 'boolean'],
            'pickup_address_id' => ['required_if:pickup_at_vendor,false', 'nullable', 'exists:addresses,id'],
            'delivery_address_id' => ['required_if:delivery_at_vendor,false', 'nullable', 'exists:addresses,id'],
            // payment_methods: string ("visa") or array (["nazefah_wallet", "visa"])
            'payment_methods' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $methods = $this->orderPaymentService->normalizePaymentMethodsInput($value);
                    if ($methods === null) {
                        $fail(__('validation.required', ['attribute' => $attribute]));

                        return;
                    }
                    $allowed = array_merge(PaymentMethod::values(), $this->orderPaymentService->walletAliases());
                    $allowed = array_values(array_unique($allowed));
                    if (! $this->orderPaymentService->paymentMethodsAreAllowed($methods, $allowed)) {
                        $fail(__('validation.in', ['attribute' => $attribute]));
                    }
                },
            ],
            'coupon_code' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:jpeg,png,jpg,gif,webp,pdf', 'max:10240'],
            'pickup_slot_date' => ['nullable', 'date', 'after_or_equal:today'],
            'pickup_slot_time' => ['nullable', 'string'],
            'delivery_slot_date' => ['nullable', 'date', 'after_or_equal:pickup_slot_date'],
            'delivery_slot_time' => ['nullable', 'string'],
            'getTimeSlots_date' => ['nullable', 'date'],
            'getTimeSlots_time' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.piece_id' => ['required', 'exists:pieces,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.service_id' => ['required', 'exists:services,id'],
            'items.*.additional_service_ids' => ['nullable', 'array'],
            'items.*.additional_service_ids.*' => ['integer', 'exists:service_additions,id'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.image' => ['nullable', 'sometimes', 'file', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();

        // Get language from current locale (set by middleware)
        $lang = app()->getLocale();

        // Get branch and vendor
        $branch = \Modules\Branch\Models\Branch::with('vendor')->find($request->branch_id);
        if (! $branch) {
            return errorResponse(__('branch.not_found'), 404);
        }

        if (! $branch->is_active) {
            return errorResponse(__('branch.not_active'), 400);
        }

        $vendor = $branch->vendor;
        if (! $vendor) {
            return errorResponse(__('order.vendor_not_found'), 404);
        }

        if (! $vendor->is_active) {
            return errorResponse(__('order.vendor_not_active'), 400);
        }

        if ($vendor->is_banned) {
            return errorResponse(__('order.vendor_banned'), 403);
        }

        $vendorId = $vendor->id;

        // Determine if delivery is needed (when either pickup or delivery is NOT at vendor)
        $needsDelivery = ! $request->boolean('pickup_at_vendor') || ! $request->boolean('delivery_at_vendor');

        DB::beginTransaction();
        try {
            $pickupAddress = null;
            $deliveryAddress = null;

            $pickupAtVendor = $request->boolean('pickup_at_vendor');
            $deliveryAtVendor = $request->boolean('delivery_at_vendor');

            // Resolve pickup address only when pickup is NOT at vendor (client sends items from home)
            if (! $pickupAtVendor) {
                if ($request->pickup_address_id) {
                    $pickupAddress = Address::where('id', $request->pickup_address_id)
                        ->where('client_id', $user->id)
                        ->first();
                    if (! $pickupAddress) {
                        return errorResponse(__('order.invalid_pickup_address'), 400);
                    }
                } else {
                    $pickupAddress = $user->defaultAddress();
                    if (! $pickupAddress) {
                        return errorResponse(__('order.no_pickup_address'), 400);
                    }
                }
            }

            // Resolve delivery address only when delivery is NOT at vendor (client receives at home)
            if (! $deliveryAtVendor) {
                if ($request->delivery_address_id) {
                    $deliveryAddress = Address::where('id', $request->delivery_address_id)
                        ->where('client_id', $user->id)
                        ->first();
                    if (! $deliveryAddress) {
                        return errorResponse(__('order.invalid_delivery_address'), 400);
                    }
                } else {
                    $deliveryAddress = $user->defaultAddress();
                    if (! $deliveryAddress) {
                        return errorResponse(__('order.no_delivery_address'), 400);
                    }
                }
            }

            // Ensure addresses are in the same zone as the branch (no cross-zone orders)
            $branchZoneId = $branch->zone_id;
            if ($branchZoneId !== null) {
                if ($pickupAddress && ! $pickupAtVendor) {
                    if ($pickupAddress->zone_id === null) {
                        return errorResponse(__('order.address_must_be_in_branch_zone'), 400);
                    }
                    if ((int) $pickupAddress->zone_id !== (int) $branchZoneId) {
                        return errorResponse(__('order.pickup_address_not_in_branch_zone'), 400);
                    }
                }
                if ($deliveryAddress && ! $deliveryAtVendor) {
                    if ($deliveryAddress->zone_id === null) {
                        return errorResponse(__('order.address_must_be_in_branch_zone'), 400);
                    }
                    if ((int) $deliveryAddress->zone_id !== (int) $branchZoneId) {
                        return errorResponse(__('order.delivery_address_not_in_branch_zone'), 400);
                    }
                }
            }

            // Use DiscountService to validate items and apply discount
            $accept = $request->header('Accept-Language', 'en');
            $lang = Str::contains(strtolower($accept), 'ar') ? 'ar' : 'en';

            $discountAmount = 0;
            $deliveryDiscountAmount = 0;
            $appliedDiscount = null;
            $totalAmount = 0;
            $itemsData = [];
            $pieces = null;
            $discountItemsBreakdown = [];

            if ($request->has('coupon_code') && $request->coupon_code) {
                // Validate coupon and calculate with items (branch-aware pricing)
                $result = $this->discountService->validateAndCalculateDiscount(
                    $request->coupon_code,
                    $request->items,
                    $user->id,
                    $vendorId,
                    $lang,
                    (int) $request->branch_id
                );

                if (! $result['success']) {
                    return errorResponse($result['message'], $result['code'], $result['errors'] ?? null);
                }

                $totalAmount = $result['data']['order_amount'];
                $discountAmount = $result['data']['discount_amount'];
                $deliveryDiscountAmount = (float) ($result['data']['delivery_discount_amount'] ?? 0);
                $appliedDiscount = $result['data']['discount'];
                $pieces = $result['data']['pieces'];
                $discountItemsBreakdown = $result['data']['items_breakdown'] ?? [];
            } else {
                // No coupon — still validate items belong to vendor
                $pieceIds = collect($request->items)->pluck('piece_id')->unique();
                $pieces = Piece::with([
                    'vendor',
                    'services',
                    'additionalServices' => function ($query) use ($request) {
                        $query->where(function ($q) use ($request) {
                            $q->where('service_addition_piece.branch_id', $request->branch_id)
                                ->orWhereNull('service_addition_piece.branch_id');
                        });
                    },
                ])
                    ->whereIn('id', $pieceIds)
                    ->where('vendor_id', $vendorId)
                    ->get();

                if ($pieces->count() !== $pieceIds->count()) {
                    return errorResponse(__('order.items_not_available'), 400);
                }

                $vendorIds = $pieces->pluck('vendor_id')->unique();
                if ($vendorIds->count() > 1) {
                    $errors = [
                        'items_vendor_ids' => $vendorIds->values()->all(),
                    ];

                    return errorResponse(__('order.items_same_vendor'), 400, $errors);
                }

                $itemsVendorId = $vendorIds->first();
                if ($itemsVendorId != $vendorId) {
                    $errors = [
                        'expected_vendor_id' => $vendorId,
                        'items_vendor_id' => $itemsVendorId,
                    ];

                    return errorResponse(__('order.items_vendor_not_match'), 400, $errors);
                }
            }

            $storeBranchId = (int) $request->branch_id;

            // Pre-load branch pieces and services for validation
            $branchPieceIds = $branch->activePieces()->pluck('pieces.id')->toArray();
            $branchServiceIds = $branch->activeServices()->pluck('services.id')->toArray();

            // Calculate item totals and build itemsData (multi main services stay one cart line)
            foreach ($request->items as $item) {
                $piece = $pieces->firstWhere('id', $item['piece_id']);

                if (! $piece) {
                    return errorResponse(trans('order.piece_not_found'), 400);
                }

                // Verify piece is available at this branch
                if (! in_array($item['piece_id'], $branchPieceIds)) {
                    $pieceName = \App\Support\OrderItemDisplayNames::pieceName($piece, $storeBranchId, $lang);

                    return errorResponse(trans('order.piece_not_available_at_branch', ['piece_name' => $pieceName]), 400);
                }

                $mainServiceIds = OrderItemsNormalizer::mainServiceIds($item);
                if ($mainServiceIds === []) {
                    $pieceName = \App\Support\OrderItemDisplayNames::pieceName($piece, $storeBranchId, $lang);

                    return errorResponse(__('order.service_not_available', ['piece_name' => $pieceName]), 400);
                }

                $servicesRows = [];
                $servicesTotal = 0.0;

                foreach ($mainServiceIds as $mainServiceId) {
                    $availabilityError = $this->catalogAvailabilityService->validateOrderLineForNewOrder(
                        $storeBranchId,
                        (int) $item['piece_id'],
                        (int) $mainServiceId,
                        $item['additional_service_ids'] ?? [],
                        $lang
                    );
                    if ($availabilityError !== null) {
                        return errorResponse($availabilityError, 400);
                    }

                    $service = $piece->services->firstWhere('id', $mainServiceId);
                    if (! $service) {
                        $pieceName = \App\Support\OrderItemDisplayNames::pieceName($piece, $storeBranchId, $lang);

                        return errorResponse(__('order.service_not_available', ['piece_name' => $pieceName]), 400);
                    }

                    if (! in_array($mainServiceId, $branchServiceIds)) {
                        $serviceName = \App\Support\OrderItemDisplayNames::serviceName($service, $storeBranchId, $lang);

                        return errorResponse(trans('order.service_not_available_at_branch', ['service_name' => $serviceName]), 400);
                    }

                    $servicePiecePrice = (float) $service->getPriceForPieceAtBranch($piece->id, $storeBranchId);
                    $servicesTotal += $servicePiecePrice;
                    $servicesRows[] = [
                        'service_id' => (int) $mainServiceId,
                        'service_piece_price' => $servicePiecePrice,
                    ];
                }

                $additionalServicesData = [];
                $additionalServicesTotal = 0.0;
                if (! empty($item['additional_service_ids'])) {
                    $uniqueAdditionalServiceIds = array_unique($item['additional_service_ids']);
                    $availableAdditions = $piece->getAdditionalServicesForBranch($storeBranchId);

                    foreach ($uniqueAdditionalServiceIds as $additionalServiceId) {
                        $additionModel = $availableAdditions->firstWhere('id', $additionalServiceId);

                        if (! $additionModel) {
                            $additionForError = \Modules\Service\Models\ServiceAddition::find($additionalServiceId);
                            $additionName = $additionForError
                                ? \App\Support\OrderItemDisplayNames::additionalServiceName($additionForError, $storeBranchId, $lang)
                                : "ID: {$additionalServiceId}";

                            return errorResponse(__('order.additional_service_not_available_at_branch', ['service_name' => $additionName]), 400);
                        }

                        $additionalPrice = (float) $additionModel->getPriceForPieceAtBranch($piece->id, $storeBranchId);
                        $additionalServicesTotal += $additionalPrice;

                        $additionalServicesData[] = \App\Support\OrderItemDisplayNames::additionalServiceLine(
                            $additionModel,
                            $storeBranchId,
                            $lang,
                            $additionalPrice
                        );
                    }
                }

                $unitPrice = $servicesTotal + $additionalServicesTotal;
                $itemTotal = $unitPrice * $item['quantity'];

                if (! $appliedDiscount) {
                    $totalAmount += $itemTotal;
                }

                $itemsData[] = [
                    'piece_id' => $item['piece_id'],
                    'services' => $servicesRows,
                    'service_id' => $servicesRows[0]['service_id'],
                    'service_piece_price' => $servicesRows[0]['service_piece_price'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $itemTotal,
                    'additional_services' => $additionalServicesData,
                    'additional_services_total' => (float) $additionalServicesTotal,
                    'note' => $item['note'] ?? null,
                    'image' => $item['image'] ?? null,
                ];
            }

            $deliveryFee = 0;
            $pickupAtVendor = $request->boolean('pickup_at_vendor');
            $deliveryAtVendor = $request->boolean('delivery_at_vendor');
            $totalDistance = 0;
            $pickupFee = 0;
            $deliveryFeeAmount = 0;

            // Calculate delivery fee:
            // - pickup_at_vendor=false & delivery_at_vendor=false → charge pickup + delivery fees
            // - pickup_at_vendor=false & delivery_at_vendor=true  → charge pickup fee only
            // - pickup_at_vendor=true  & delivery_at_vendor=false → charge delivery fee only
            // - pickup_at_vendor=true  & delivery_at_vendor=true  → no delivery charge
            if (! $pickupAtVendor || ! $deliveryAtVendor) {
                // Use branch location for delivery calculations
                if (! $branch->latitude || ! $branch->longitude) {
                    return errorResponse(__('order.vendor_location_not_available'), 400);
                }

                // Use vendor's delivery_price_per_km if set, otherwise fall back to admin setting
                $deliveryPricePerKm = $vendor->delivery_price_per_km
                    ?? AdminSetting::getValue('delivery_price_per_km', 5);

                // Calculate pickup fee (customer address → branch) if pickup is via delivery
                if (! $pickupAtVendor && $pickupAddress) {
                    if (! $pickupAddress->latitude || ! $pickupAddress->longitude) {
                        return errorResponse(__('order.pickup_address_location_not_available'), 400);
                    }
                    $pickupDistance = $this->calculateDistance(
                        (float) $pickupAddress->latitude,
                        (float) $pickupAddress->longitude,
                        (float) $branch->latitude,
                        (float) $branch->longitude
                    );
                    $totalDistance += $pickupDistance;
                    $pickupFee = $pickupDistance * $deliveryPricePerKm;
                }

                // Calculate delivery fee (branch → customer address) if delivery is via delivery
                if (! $deliveryAtVendor && $deliveryAddress) {
                    if (! $deliveryAddress->latitude || ! $deliveryAddress->longitude) {
                        return errorResponse(__('order.delivery_address_location_not_available'), 400);
                    }
                    $deliveryDistance = $this->calculateDistance(
                        (float) $branch->latitude,
                        (float) $branch->longitude,
                        (float) $deliveryAddress->latitude,
                        (float) $deliveryAddress->longitude
                    );
                    $totalDistance += $deliveryDistance;
                    $deliveryFeeAmount = $deliveryDistance * $deliveryPricePerKm;
                }

                // Sum applicable fees
                $deliveryFee = $pickupFee + $deliveryFeeAmount;
            }

            $discountCity = $deliveryAddress?->city ?? $pickupAddress?->city;
            if ($appliedDiscount && $discountItemsBreakdown !== []) {
                $rechecked = $this->discountService->evaluateKnownOrderDiscount(
                    $appliedDiscount,
                    $discountItemsBreakdown,
                    (float) $totalAmount,
                    (int) $user->id,
                    (int) $vendorId,
                    $storeBranchId,
                    (float) $deliveryFee,
                    $discountCity,
                    false,
                    $lang
                );
                $discountAmount = (float) $rechecked['discount_amount'];
                $deliveryDiscountAmount = (float) ($rechecked['delivery_discount_amount'] ?? 0);
            } elseif (! $request->filled('coupon_code')) {
                $automatic = $this->discountService->findBestAutomaticOrderDiscount(
                    $request->items,
                    (int) $user->id,
                    (int) $vendorId,
                    $lang,
                    $storeBranchId,
                    (float) $deliveryFee,
                    $discountCity
                );
                if ($automatic['applied']) {
                    $appliedDiscount = $automatic['discount'];
                    $discountAmount = (float) $automatic['discount_amount'];
                    $deliveryDiscountAmount = (float) ($automatic['delivery_discount_amount'] ?? 0);
                }
            }

            $pricingTotals = Order::calculatePricingTotals($totalAmount, $discountAmount, $deliveryFee, $deliveryDiscountAmount);
            $taxAmount = $pricingTotals['tax_amount'];
            $finalAmount = $pricingTotals['final_amount'];

            $pickupDate = $request->input('pickup_slot_date');
            $pickupTime = $request->input('pickup_slot_time');
            $deliveryDate = $request->input('delivery_slot_date') ?? $request->input('getTimeSlots_date');
            $deliveryTime = $request->input('delivery_slot_time') ?? $request->input('getTimeSlots_time');

            $pickupDatetime = ($pickupDate && $pickupTime) ? ($pickupDate.' '.$pickupTime) : null;
            $deliveryDatetime = ($deliveryDate && $deliveryTime) ? ($deliveryDate.' '.$deliveryTime) : null;

            $orderNumber = $this->generateUniqueOrderNumber();
            $qrCode = $orderNumber.'-'.time();

            // Handle file attachments upload
            $uploadedAttachments = [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $mimeType = $file->getMimeType();
                    $extension = $file->getClientOriginalExtension();

                    if (str_starts_with($mimeType, 'image/')) {
                        $uploadedPath = $this->uploadFilesService->uploadImage($file, 'orders/attachments');
                        $type = 'image';
                    } else {
                        $uploadedPath = $this->uploadFilesService->uploadFile($file, 'orders/attachments');
                        $type = strtolower($extension);
                    }

                    $uploadedAttachments[] = [
                        'url' => $uploadedPath,
                        'type' => $type,
                        'name' => $file->getClientOriginalName(),
                    ];
                }
            }

            // Upload item images before branching.
            // Multipart files live on $request->file('items.N.image'), not in input('items').
            foreach ($itemsData as $index => &$itemData) {
                $imageFile = $itemData['image'] ?? null;
                if (! ($imageFile instanceof \Illuminate\Http\UploadedFile)) {
                    $imageFile = $request->file("items.{$index}.image");
                }

                if ($imageFile instanceof \Illuminate\Http\UploadedFile) {
                    $itemData['uploaded_image'] = $this->uploadFilesService->uploadImage($imageFile, 'orders/items');
                } else {
                    $itemData['uploaded_image'] = null;
                }
                unset($itemData['image']);
            }
            unset($itemData);

            $paymentMethods = $this->orderPaymentService->normalizePaymentMethodsInput($request->input('payment_methods'));
            $checkoutLegs = null;
            $checkoutGatewayPayments = [];
            $checkoutWalletPayments = [];
            $checkoutPaymentSummary = [];

            if (count($paymentMethods) === 1 && $paymentMethods[0] === PaymentMethod::CASH_ON_DELIVERY->value) {
                $checkoutLegs = [
                    ['payment_method' => PaymentMethod::CASH_ON_DELIVERY->value, 'amount' => (float) $finalAmount],
                ];
            } else {
                $walletBalance = app(OrderPaymentService::class)->availableWalletBalance((int) $user->id);
                $alloc = $this->orderPaymentService->allocateSurcharge(
                    $paymentMethods,
                    (float) $finalAmount,
                    $walletBalance
                );

                if ($alloc['error']) {
                    if ($alloc['error'] === 'order.insufficient_wallet_balance_short') {
                        return jsonResponse(false, 402, __($alloc['error']), [
                            'payment_required' => true,
                            'amount_due' => (float) $finalAmount,
                            'available_methods' => $this->orderPaymentService->gatewayMethods(),
                        ]);
                    }

                    return errorResponse(__($alloc['error'], $alloc['params'] ?? []), 422);
                }

                $checkoutLegs = $alloc['legs'];
            }

            // === DIRECT ORDER FLOW: cash, wallet-only, gateway, or split ===

            $orderPaymentMethod = $this->resolvePrimaryPaymentMethodFromLegs($checkoutLegs);
            $hasGatewayLeg = $this->orderPaymentService->splitHasGatewayLeg($checkoutLegs);

            if ($hasGatewayLeg) {
                $pendingOrder = \Modules\Order\Models\PendingOrder::create([
                    'client_id' => $user->id,
                    'vendor_id' => $vendorId,
                    'order_data' => [
                        'client_id' => $user->id,
                        'branch_id' => $request->branch_id,
                        'order_number' => $orderNumber,
                        'status' => OrderStatus::PENDING->value,
                        'total_amount' => $totalAmount,
                        'discount_amount' => $discountAmount,
                        'discount_id' => $appliedDiscount?->id,
                        'tax_amount' => $taxAmount,
                        'delivery_fee' => (float) $pricingTotals['delivery_fee'],
                        'final_amount' => $finalAmount,
                        'pickup_at_vendor' => $pickupAtVendor,
                        'delivery_at_vendor' => $deliveryAtVendor,
                        'pickup_address_id' => $pickupAtVendor ? null : ($pickupAddress->id ?? null),
                        'delivery_address_id' => $deliveryAtVendor ? null : ($deliveryAddress->id ?? null),
                        'pickup_time' => $pickupDatetime,
                        'estimated_delivery_time' => $deliveryDatetime,
                        'notes' => $request->notes,
                        'attachments' => ! empty($uploadedAttachments) ? $uploadedAttachments : null,
                        'qr_code' => $qrCode,
                        'payment_method' => $orderPaymentMethod,
                        'payment_methods' => $paymentMethods,
                        'distance' => $totalDistance,
                    ],
                    'items_data' => $itemsData,
                    'discount_id' => $appliedDiscount?->id,
                    'payment_method' => $orderPaymentMethod,
                    'status' => 'pending',
                    'expires_at' => now()->addMinutes(5),
                ]);

                try {
                    $settle = $this->orderPaymentService->settleSplitLegsForPendingOrder($pendingOrder, $checkoutLegs, $user, [
                        'meta' => ['reason' => 'order_create'],
                    ]);
                    $checkoutGatewayPayments = $settle['gateway_payments'];
                    $checkoutWalletPayments = $settle['wallet_payments'] ?? [];
                    $checkoutPaymentSummary = $settle['summary'] ?? [];
                } catch (\Modules\Order\Exceptions\InsufficientWalletBalanceException $e) {
                    DB::rollBack();

                    return jsonResponse(false, 402, __('order.insufficient_wallet_balance_short'), [
                        'payment_required' => true,
                        'amount_due' => (float) $finalAmount,
                        'wallet_balance' => $e->available,
                        'available_methods' => $this->orderPaymentService->gatewayMethods(),
                    ]);
                } catch (\Throwable $e) {
                    DB::rollBack();
                    \Illuminate\Support\Facades\Log::error('Order creation payment init failed', [
                        'error' => $e->getMessage(),
                    ]);

                    return errorResponse(__('order.payment_init_failed'), 400);
                }

                DB::commit();

                $responseData = [
                    'order' => [
                        'order_id' => null,
                        'pending_order_id' => $pendingOrder->id,
                        'order_number' => $orderNumber,
                        'status_label' => 'قيد الانتظار',
                        'total_amount' => (float) $totalAmount,
                        'discount_amount' => (float) $discountAmount,
                        'tax_amount' => (float) $taxAmount,
                        'delivery_fee' => (float) $pricingTotals['delivery_fee'],
                        'final_amount' => (float) $finalAmount,
                        'gateway_payments' => $checkoutGatewayPayments,
                        'wallet_payments' => $checkoutWalletPayments,
                        'payment_summary' => $checkoutPaymentSummary,
                        'payment_url' => $checkoutGatewayPayments[0]['payment_url'] ?? null,
                        'payment_params' => $checkoutGatewayPayments[0]['payment_params'] ?? null,
                        'payment_form_html' => $checkoutGatewayPayments[0]['payment_form_html'] ?? null,
                        'redirect_instructions' => $checkoutGatewayPayments[0]['redirect_instructions'] ?? null,
                        'payment_status' => 'pending',
                        'payment_status_label' => \App\Support\PaymentStatusPresenter::label('pending'),
                        'qr_code' => $qrCode,
                        'distance' => (float) $totalDistance,
                        'total_distance_km' => (float) $totalDistance,
                        'pickup_at_vendor' => (bool) $pickupAtVendor,
                        'delivery_at_vendor' => (bool) $deliveryAtVendor,
                        'pickup_time' => $pickupDatetime,
                        'estimated_delivery_time' => $deliveryDatetime,
                    ],
                ];

                return jsonResponse(true, 200, __('order.created_successfully'), $responseData);
            }

            $order = Order::create([
                'client_id' => $user->id,
                'branch_id' => $request->branch_id,
                'order_number' => $orderNumber,
                'status' => OrderStatus::PENDING->value,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'discount_id' => $appliedDiscount?->id,
                'tax_amount' => $taxAmount,
                'delivery_fee' => (float) $pricingTotals['delivery_fee'],
                'final_amount' => $finalAmount,
                'pickup_at_vendor' => $pickupAtVendor,
                'delivery_at_vendor' => $deliveryAtVendor,
                'pickup_address_id' => $pickupAtVendor ? null : ($pickupAddress->id ?? null),
                'delivery_address_id' => $deliveryAtVendor ? null : ($deliveryAddress->id ?? null),
                'pickup_time' => $pickupDatetime,
                'estimated_delivery_time' => $deliveryDatetime,
                'notes' => $request->notes,
                'attachments' => ! empty($uploadedAttachments) ? $uploadedAttachments : null,
                'qr_code' => $qrCode,
                'payment_method' => $orderPaymentMethod,
                'payment_methods' => $paymentMethods,
                'distance' => $totalDistance,
            ]);

            foreach ($itemsData as $index => $itemData) {
                $quantityToCreate = (int) $itemData['quantity'];
                $serviceRows = $itemData['services'] ?? [[
                    'service_id' => $itemData['service_id'],
                    'service_piece_price' => $itemData['service_piece_price'],
                ]];
                $additionalServicesTotal = (float) ($itemData['additional_services_total'] ?? 0);

                for ($i = 0; $i < $quantityToCreate; $i++) {
                    $lineGroup = count($serviceRows) > 1 ? (string) Str::uuid() : null;

                    foreach ($serviceRows as $serviceIndex => $serviceRow) {
                        $isPrimary = $serviceIndex === 0;
                        $servicePrice = (float) $serviceRow['service_piece_price'];
                        // Additions attach once per piece line (primary service row only).
                        $rowUnitPrice = $isPrimary
                            ? ($servicePrice + $additionalServicesTotal)
                            : $servicePrice;

                        $orderItem = OrderItem::create([
                            'order_id' => $order->id,
                            'piece_id' => $itemData['piece_id'],
                            'service_id' => $serviceRow['service_id'],
                            'line_group' => $lineGroup,
                            'piece_price' => 0,
                            'service_price' => $servicePrice,
                            'quantity' => 1,
                            'unit_price' => $rowUnitPrice,
                            'total_price' => $rowUnitPrice,
                            'notes' => $itemData['note'] ?? null,
                            'images' => $itemData['uploaded_image'] ?? null,
                        ]);

                        if ($isPrimary && ! empty($itemData['additional_services'])) {
                            foreach ($itemData['additional_services'] as $additionalService) {
                                OrderItemAdditionalService::create([
                                    'order_item_id' => $orderItem->id,
                                    'service_addition_id' => $additionalService['id'],
                                    'price' => $additionalService['price'],
                                    'quantity' => 1,
                                ]);
                            }
                        }
                    }
                }
            }

            if ($appliedDiscount) {
                $this->discountService->incrementUsage($appliedDiscount);
            }

            OrderStatusLog::create([
                'order_id' => $order->id,
                'status' => OrderStatus::PENDING->value,
                'notes' => 'Order created',
                'changed_by' => $user->id,
            ]);

            $chatService = app(\Modules\Chat\Services\ChatService::class);
            $chatService->ensureConversationForOrder($order->id, $user->id, $vendorId, null);
            $chatService->ensureConversationForOrder($order->id, $user->id, null, null);

            try {
                $settle = $this->orderPaymentService->settleSplitLegs($order, $checkoutLegs, $user, [
                    'is_surcharge' => false,
                    'meta' => ['reason' => 'order_create'],
                ]);
                $checkoutGatewayPayments = $settle['gateway_payments'];
            } catch (\Modules\Order\Exceptions\InsufficientWalletBalanceException $e) {
                DB::rollBack();

                return jsonResponse(false, 402, __('order.insufficient_wallet_balance_short'), [
                    'payment_required' => true,
                    'amount_due' => (float) $finalAmount,
                    'wallet_balance' => $e->available,
                    'available_methods' => $this->orderPaymentService->gatewayMethods(),
                ]);
            } catch (\RuntimeException $e) {
                DB::rollBack();

                return errorResponse(__('order.payment_init_failed'), 400);
            }

            DB::commit();

            $order->load([
                'items.piece',
                'items.service',
                'items.additionalServicesPivot.serviceAddition',
                'pickupAddress',
                'deliveryAddress',
                'vendor',
                'branch.vendor',
            ]);

            if (empty($checkoutGatewayPayments)) {
                app(\App\Services\OrderNotificationService::class)
                    ->sendOrderCreatedNotificationsIfNeeded($order);

                app(\Modules\Invoice\Services\InvoiceService::class)
                    ->issueForOrder($order->fresh(['client', 'branch.vendor', 'items.piece', 'items.service']), null, 'order_create_paid');
            }

            $responseData = [
                'order' => array_merge([
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status_label' => $order->status_label,
                    'total_amount' => (float) $order->total_amount,
                    'discount_amount' => (float) $order->discount_amount,
                    ...$order->couponResponseFields($lang),
                    'tax_amount' => (float) $order->tax_amount,
                    'delivery_fee' => (float) $order->delivery_fee,
                    'final_amount' => (float) $order->final_amount,
                    ...$order->paymentFieldsForApi(),
                    'payment_status' => $order->payment_status ?? 'pending',
                    'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
                    'qr_code' => $order->qr_code,
                    'distance' => $order->distance !== null ? (float) $order->distance : 0,
                    'total_distance_km' => $order->distance !== null ? (float) $order->distance : 0,
                    'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                    'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                    'driver_id' => $order->driver_id,
                    'pickup_driver_id' => $order->pickup_driver_id,
                    'delivery_driver_id' => $order->delivery_driver_id,
                    'pickup_time' => $order->pickup_time,
                    'estimated_delivery_time' => $order->estimated_delivery_time,
                    'pickup_address' => ($order->pickup_at_vendor || ! $order->pickup_address_id) ? null : ($order->pickupAddress ? [
                        'id' => $order->pickupAddress->id,
                        'address' => $order->pickupAddress->address_text ?? $order->pickupAddress->street_name,
                        'street_name' => $order->pickupAddress->street_name,
                        'building_number' => $order->pickupAddress->building_number,
                        ...$order->pickupAddress->getApiFloorAttributes(),
                        'apartment' => $order->pickupAddress->apartment,
                        'city' => $order->pickupAddress->city,
                        'district' => $order->pickupAddress->district,
                        'latitude' => $order->pickupAddress->latitude ? (float) $order->pickupAddress->latitude : null,
                        'longitude' => $order->pickupAddress->longitude ? (float) $order->pickupAddress->longitude : null,
                    ] : null),
                    'delivery_address' => ($order->delivery_at_vendor || ! $order->delivery_address_id) ? null : ($order->deliveryAddress ? [
                        'id' => $order->deliveryAddress->id,
                        'address' => $order->deliveryAddress->address_text ?? $order->deliveryAddress->street_name,
                        'street_name' => $order->deliveryAddress->street_name,
                        'building_number' => $order->deliveryAddress->building_number,
                        ...$order->deliveryAddress->getApiFloorAttributes(),
                        'apartment' => $order->deliveryAddress->apartment,
                        'city' => $order->deliveryAddress->city,
                        'district' => $order->deliveryAddress->district,
                        'latitude' => $order->deliveryAddress->latitude ? (float) $order->deliveryAddress->latitude : null,
                        'longitude' => $order->deliveryAddress->longitude ? (float) $order->deliveryAddress->longitude : null,
                    ] : null),
                    'branch' => $order->branch
                        ? $order->branch->toApiOrderBranch($lang, [
                            'delivery_price_per_km' => (float) (
                                $order->branch->vendor?->delivery_price_per_km
                                ?? $order->vendor?->delivery_price_per_km
                                ?? 0
                            ),
                        ])
                        : null,
                    'vendor' => $order->vendor ? [
                            'id' => $order->vendor->id,
                            'name' => $order->vendor->getTranslatedName($lang),
                            'logo' => $this->uploadFilesService->getFullUrl($order->vendor->logo),
                        ] : null,
                    'items' => OrderItemGrouper::toApiLines(
                        $order->items,
                        (int) ($order->branch_id ?? 0),
                        $lang,
                        fn ($item) => $item->images ? $this->uploadFilesService->getFullUrl($item->images) : null
                    ),
                    'notes' => $order->notes,
                    'attachments' => $order->attachments ? collect($order->attachments)->map(function ($attachment) {
                        return [
                            'url' => $this->uploadFilesService->getFullUrl($attachment['url'] ?? ''),
                            'type' => $attachment['type'] ?? 'file',
                            'name' => $attachment['name'] ?? '',
                        ];
                    })->toArray() : [],
                ], $order->clientVisitResponseFields()),
            ];

            $responseData['payment'] = $this->orderPaymentService->buildSplitPaymentResponse(
                $order,
                $checkoutGatewayPayments,
                (float) $order->final_amount,
                null,
                false,
                false
            );

            return successResponse($responseData, __('order.order_created'), 201);

        } catch (InsufficientWalletBalanceException $e) {
            DB::rollBack();

            return jsonResponse(false, 402, __('order.insufficient_wallet_balance_short'), [
                'payment_required' => true,
                'amount_due' => $e->amountDue,
                'wallet_balance' => $e->available,
                'available_methods' => $this->orderPaymentService->gatewayMethods(),
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'order.payment_init_failed') {
                DB::rollBack();

                return errorResponse(__('order.payment_init_failed'), 400);
            }

            DB::rollBack();

            return serverErrorResponse(__('order.order_create_failed').': '.$e->getMessage());
        } catch (\Exception $e) {
            DB::rollBack();

            return serverErrorResponse(__('order.order_create_failed').': '.$e->getMessage());
        }
    }

    /**
     * Pick the order's stored payment_method from a split: prefer the gateway
     * leg, otherwise wallet.
     *
     * @param  array<int, array{payment_method: string, amount: float}>  $legs
     */
    private function resolvePrimaryPaymentMethodFromLegs(array $legs): string
    {
        foreach ($legs as $leg) {
            if (! $this->orderPaymentService->isWalletMethod($leg['payment_method'])) {
                return $leg['payment_method'];
            }
        }

        return PaymentMethod::Nathefah_WALLET->value;
    }

    /**
     * Get order details
     *
     * @param  string  $order_id
     */
    public function show(Request $request, $order_id): JsonResponse
    {
        $order_id = (int) $order_id;
        $user = $request->user();

        $order = Order::with([
            'vendor',
            'branch.vendor',
            'client.addresses',
            'latestPayment',
            'discount',
            // Soft-deleted lines (post-edit replacements) are not part of the live order.
            'items' => fn ($q) => $q->with([
                'piece.iconRelation',
                'service.iconRelation',
                'additionalServicesPivot.serviceAddition.iconRelation',
            ]),
            'pickupAddress',
            'deliveryAddress',
            'driver',
        ])->where('client_id', $user->id)->find($order_id);

        // Get language from current locale (set by middleware)
        $lang = app()->getLocale();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $visitService = app(\App\Services\ClientOrderVisitService::class);
        $handoffService = app(\App\Services\ClientOrderHandoffService::class);

        // Calculate time remaining until pickup
        $timeRemaining = null;
        if ($order->pickup_time) {
            $now = now();
            $pickupTime = \Carbon\Carbon::parse($order->pickup_time);

            if ($pickupTime->isFuture()) {
                $diff = $now->diff($pickupTime);

                // Format time remaining
                $parts = [];
                if ($diff->d > 0) {
                    $parts[] = $diff->d.' '.($lang === 'ar' ? ($diff->d == 1 ? 'يوم' : 'أيام') : ($diff->d == 1 ? 'day' : 'days'));
                }
                if ($diff->h > 0) {
                    $parts[] = $diff->h.' '.($lang === 'ar' ? ($diff->h == 1 ? 'ساعة' : 'ساعات') : ($diff->h == 1 ? 'hour' : 'hours'));
                }
                if ($diff->i > 0 && $diff->d == 0) {
                    $parts[] = $diff->i.' '.($lang === 'ar' ? ($diff->i == 1 ? 'دقيقة' : 'دقائق') : ($diff->i == 1 ? 'minute' : 'minutes'));
                }

                $timeRemaining = ! empty($parts) ? implode(' '.($lang === 'ar' ? 'و' : 'and').' ', $parts) : null;
            }
        }

        $branchData = $order->branch
            ? $order->branch->toApiOrderBranchFlat($lang, [
                'phone_number' => $order->branch->phone_number,
                'delivery_price_per_km' => (float) (
                    $order->branch->vendor?->delivery_price_per_km
                    ?? $order->vendor?->delivery_price_per_km
                    ?? 0
                ),
            ])
            : null;

        // Prepare driver data
        $driverData = null;
        if ($order->driver) {
            $driverData = [
                'id' => $order->driver->id,
                'name' => $order->driver->name,
                'phone' => $order->driver->phone,
            ];
        }

        $clientDefaultAddress = null;
        if ($order->client) {
            if ($order->client->relationLoaded('addresses')) {
                $clientDefaultAddress = $order->client->addresses->where('is_default', true)->first();
            } else {
                $clientDefaultAddress = $order->client->defaultAddress();
            }
        }

        // Build items: group multi-service piece lines; totals include accepted additions
        $branchId = (int) ($order->branch_id ?? 0);
        $categorizedItems = $this->categorizePendingApprovalItems($order, $lang);
        $mappedItems = collect(OrderItemGrouper::toApiLines(
            $order->items,
            $branchId,
            $lang,
            fn ($item) => $item->images ? $this->uploadFilesService->getFullUrl($item->images) : null
        ))->values();

        // Use stored order totals so list, show, and store response stay consistent
        $subtotal = (float) $order->total_amount;
        $deliveryFee = (float) $order->delivery_fee;
        $discount = (float) $order->discount_amount;
        $tax = (float) $order->tax_amount;
        $finalTotal = (float) $order->final_amount;

        // Full gateway + wallet breakdown from the persisted payment legs, so the
        // client sees the same split (per method, held/paid, summary) it saw at
        // checkout. amount_due is pinned to final_amount even when no legs exist.
        $paymentBreakdown = $this->orderPaymentService->buildSplitPaymentResponse($order, [], $finalTotal);

        return successResponse([
            'order' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_label' => OrderStatus::fromString($order->status)?->localizedLabel($order->payment_method, $order->status === OrderStatus::COMPLETED->value && ! $order->client_delivery_handoff_at, (bool) $order->delivery_at_vendor) ?? $order->status,
                'can_be_cancelled' => OrderStatus::fromString($order->status)?->canBeCancelled() ?? false,
                'can_be_rated' => OrderStatus::fromString($order->status)?->canBeRated() ?? false,
                'laundry' => $order->vendor ? [
                    'id' => $order->vendor->id,
                    'name' => $order->vendor->getTranslatedName($lang),
                    'logo' => $this->uploadFilesService->getFullUrl($order->vendor->logo),
                ] : null,
                'branch' => $branchData,
                'driver' => $driverData,
                'client_address' => $clientDefaultAddress?->toApiClientAddressArray(),
                'items' => $mappedItems,
                'rejected_items' => $categorizedItems['rejected'],
                'rejected_count' => count($categorizedItems['rejected']),
                'modified_items' => $categorizedItems['modified'],
                'modified_count' => count($categorizedItems['modified']),
                'distance' => $order->distance !== null ? (float) $order->distance : 0,
                'total_distance_km' => $order->distance !== null ? (float) $order->distance : 0,
                'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                'driver_id' => $order->driver_id,
                'pickup_driver_id' => $order->pickup_driver_id,
                'delivery_driver_id' => $order->delivery_driver_id,
                'pickup_address' => ($order->pickup_at_vendor || ! $order->pickup_address_id) ? null : ($order->pickupAddress ? [
                    'id' => $order->pickupAddress->id,
                    'address_text' => $order->pickupAddress->address_text ?? $order->pickupAddress->street_name,
                    'street_name' => $order->pickupAddress->street_name,
                    'building_number' => $order->pickupAddress->building_number ?? null,
                    ...$order->pickupAddress->getApiFloorAttributes(),
                    'apartment_number' => $order->pickupAddress->apartment ?? null,
                    'city' => $order->pickupAddress->city ?? null,
                    'district' => $order->pickupAddress->district ?? null,
                    'latitude' => (float) $order->pickupAddress->latitude,
                    'longitude' => (float) $order->pickupAddress->longitude,
                ] : null),
                'delivery_address' => ($order->delivery_at_vendor || ! $order->delivery_address_id) ? null : ($order->deliveryAddress ? [
                    'id' => $order->deliveryAddress->id,
                    'address_text' => $order->deliveryAddress->address_text ?? $order->deliveryAddress->street_name,
                    'street_name' => $order->deliveryAddress->street_name,
                    'building_number' => $order->deliveryAddress->building_number ?? null,
                    ...$order->deliveryAddress->getApiFloorAttributes(),
                    'apartment_number' => $order->deliveryAddress->apartment ?? null,
                    'city' => $order->deliveryAddress->city ?? null,
                    'district' => $order->deliveryAddress->district ?? null,
                    'latitude' => (float) $order->deliveryAddress->latitude,
                    'longitude' => (float) $order->deliveryAddress->longitude,
                ] : null),
                'total_amount' => $subtotal,
                'discount_amount' => $discount,
                ...$order->couponResponseFields($lang),
                'tax_amount' => $tax,
                'delivery_fee' => $deliveryFee,
                'final_amount' => $finalTotal,
                'payment_method' => $order->payment_method,
                'payment_method_label' => \App\Enums\PaymentMethod::labelFor($order->payment_method),
                'card_brand' => $order->card_brand,
                'card_brand_label' => \App\Enums\PaymentMethod::labelFor($order->card_brand),
                'payment_status' => $order->payment_status ?? 'pending',
                'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
                'payment_breakdown' => $order->paymentBreakdownForApi(),
                'gateway_payments' => $paymentBreakdown['gateway_payments'],
                'wallet_payments' => $paymentBreakdown['wallet_payments'],
                'payment_legs' => $paymentBreakdown['payment_legs'],
                'payment_summary' => $paymentBreakdown['summary'],
                'notes' => $order->notes,
                'attachments' => $order->attachments ? collect($order->attachments)->map(function ($attachment) {
                    return [
                        'url' => $this->uploadFilesService->getFullUrl($attachment['url'] ?? ''),
                        'type' => $attachment['type'] ?? 'file',
                        'name' => $attachment['name'] ?? '',
                    ];
                })->toArray() : [],
                'pickup_time' => $order->pickup_time ? $order->pickup_time->toISOString() : null,
                'estimated_delivery_time' => $order->estimated_delivery_time ? $order->estimated_delivery_time->toISOString() : null,
                'actual_delivery_time' => $order->actual_delivery_time ? $order->actual_delivery_time->toISOString() : null,
                'time_remaining' => $timeRemaining,
                'rating' => $order->rating,
                'review' => $order->review,
                'cancelled_reason' => $order->cancelled_reason,
                'cancelled_at' => $order->cancelled_at ? $order->cancelled_at->toISOString() : null,
                'created_at' => $order->created_at->toISOString(),
                'updated_at' => $order->updated_at->toISOString(),
                ...$order->clientVisitResponseFields(),
                'requires_handoff_confirmation' => $handoffService->canConfirmHandoff($order),
                ...$visitService->apiResponseFields($order),
                'can_accept' => ($order->status === OrderStatus::BRANCH_REVIEW->value) || in_array($order->status, [
                    OrderStatus::PENDING->value,
                    OrderStatus::DELIVERED_TO_BRANCH->value,
                    OrderStatus::COMPLETED->value,
                    OrderStatus::ON_WAY_TO_PICKUP->value,
                    OrderStatus::ON_WAY_TO_DELIVERY->value,
                    OrderStatus::DELIVERED->value,
                ]),
                'can_reject' => (OrderStatus::fromString($order->status)?->canBeCancelled() ?? false) || in_array($order->status, [
                    OrderStatus::BRANCH_REVIEW->value,
                ]),
            ],
        ], __('order.order_details_retrieved'));
    }

    /**
     * Get available discounts
     */
    public function getDiscounts(Request $request): JsonResponse
    {
        if ($request->has('items')) {
            $request->merge([
                'items' => OrderItemsNormalizer::normalize($request->input('items', [])),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            // Optional order context — when provided, each discount is actually
            // checked against it (amount, zone, items, etc.) to fill is_valid.
            // Without it, is_valid always comes back true (nothing to check yet).
            'items' => ['nullable', 'array'],
            'items.*.piece_id' => ['required_with:items', 'integer', 'exists:pieces,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'pickup_address_id' => ['nullable', 'exists:addresses,id'],
            'delivery_address_id' => ['nullable', 'exists:addresses,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        $lang = app()->getLocale();

        // Get branch and vendor
        $branch = \Modules\Branch\Models\Branch::with('vendor')->find($request->branch_id);
        if (! $branch || ! $branch->vendor) {
            return errorResponse(__('branch.not_found'), 404);
        }

        $vendorId = $branch->vendor->id;

        $hasOrderContext = $request->filled('items');
        $orderCity = null;
        if ($hasOrderContext) {
            $pickupAddress = $request->pickup_address_id
                ? Address::where('id', $request->pickup_address_id)->where('client_id', $user->id)->first()
                : null;
            $deliveryAddress = $request->delivery_address_id
                ? Address::where('id', $request->delivery_address_id)->where('client_id', $user->id)->first()
                : null;
            $orderCity = $deliveryAddress?->city ?? $pickupAddress?->city;
        }

        // Base query for active discounts within valid date range and usage limit
        $query = Discount::with(['vendors', 'zones', 'clients'])
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('usage_limit')
                    ->orWhereRaw('used_count < usage_limit');
            });

        $discounts = $query->get()->filter(function ($discount) use ($vendorId) {
            // Filter based on discount_type - show all discounts applicable to this vendor
            switch ($discount->discount_type) {
                case Discount::DISCOUNT_TYPE_GLOBAL:
                case Discount::DISCOUNT_TYPE_DELIVERY_FREE:
                case Discount::DISCOUNT_TYPE_CLIENT:
                case Discount::DISCOUNT_TYPE_ZONE:
                    // Available to all users (will validate eligibility at checkout)
                    return true;

                case Discount::DISCOUNT_TYPE_VENDORS:
                    // Only if vendor is in the discount's vendor list
                    return $discount->vendors->contains('id', $vendorId);

                default:
                    return false;
            }
        })->values()->map(function ($discount) use ($hasOrderContext, $request, $user, $vendorId, $lang, $orderCity) {
            $isValid = true;
            if ($hasOrderContext) {
                // Reuses the exact same validation the client hits when actually
                // typing this code in at checkout — a code only shows as valid here
                // if applying it for real, right now, with these items, would succeed.
                $result = $this->discountService->validateAndCalculateDiscount(
                    (string) $discount->code,
                    $request->items,
                    (int) $user->id,
                    $vendorId,
                    $lang,
                    (int) $request->branch_id,
                    0.0,
                    $orderCity
                );
                $isValid = (bool) ($result['success'] ?? false);
            }

            return [
                'id' => $discount->id,
                'title' => $discount->name,
                'discount_code' => $discount->code,
                'description' => $discount->description,
                'discount_type' => $discount->discount_type,
                'type' => $discount->type,
                'value' => (float) $discount->value,
                'min_order_amount' => (float) ($discount->min_order_amount ?? 0),
                'max_discount_amount' => (float) ($discount->max_discount_amount ?? 0),
                'start_date' => $discount->start_date?->toISOString(),
                'end_date' => $discount->end_date?->toISOString(),
                'is_valid' => $isValid,
            ];
        });

        return successResponse(
            $discounts,
            'Discounts retrieved successfully'
        );
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $request->merge([
            'items' => OrderItemsNormalizer::normalize($request->input('items', [])),
        ]);

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'exists:branches,id'],
            'pickup_at_vendor' => ['nullable', 'boolean'],
            'delivery_at_vendor' => ['nullable', 'boolean'],
            'pickup_address_id' => [
                Rule::requiredIf(fn () => $request->has('pickup_at_vendor') && ! $request->boolean('pickup_at_vendor')),
                'nullable',
                'exists:addresses,id',
            ],
            'delivery_address_id' => [
                Rule::requiredIf(fn () => $request->has('delivery_at_vendor') && ! $request->boolean('delivery_at_vendor')),
                'nullable',
                'exists:addresses,id',
            ],
            'coupon_code' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.piece_id' => ['required', 'exists:pieces,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.service_id' => ['required', 'exists:services,id'],
            'items.*.additional_service_ids' => ['nullable', 'array'],
            'items.*.additional_service_ids.*' => ['integer', 'exists:service_additions,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        $lang = app()->getLocale();

        $branch = \Modules\Branch\Models\Branch::with('vendor')->find($request->branch_id);
        if (! $branch) {
            return errorResponse(__('branch.not_found'), 404);
        }

        if (! $branch->is_active) {
            return errorResponse(__('branch.not_active'), 400);
        }

        $vendor = $branch->vendor;
        if (! $vendor) {
            return errorResponse(__('order.vendor_not_found'), 404);
        }

        if (! $vendor->is_active) {
            return errorResponse(__('order.vendor_not_active'), 400);
        }

        if ($vendor->is_banned) {
            return errorResponse(__('order.vendor_banned'), 403);
        }

        $vendorId = $vendor->id;

        try {
            $pickupAtVendor = $request->has('pickup_at_vendor') ? $request->boolean('pickup_at_vendor') : true;
            $deliveryAtVendor = $request->has('delivery_at_vendor') ? $request->boolean('delivery_at_vendor') : true;

            $addresses = $this->resolveOrderAddressesForCalculation(
                $request,
                $user,
                $branch,
                $pickupAtVendor,
                $deliveryAtVendor
            );
            if ($addresses instanceof JsonResponse) {
                return $addresses;
            }
            $pickupAddress = $addresses['pickupAddress'];
            $deliveryAddress = $addresses['deliveryAddress'];

            $result = $this->discountService->validateAndCalculateDiscount(
                $request->coupon_code,
                $request->items,
                $user->id,
                $vendorId,
                $lang,
                (int) $request->branch_id
            );

            if (! $result['success']) {
                return errorResponse($result['message'], $result['code'], $result['errors'] ?? null);
            }

            $totalAmount = $result['data']['order_amount'];
            $discountAmount = $result['data']['discount_amount'];
            $deliveryDiscountAmount = (float) ($result['data']['delivery_discount_amount'] ?? 0);
            $appliedDiscount = $result['data']['discount'];
            $pieces = $result['data']['pieces'];
            $discountItemsBreakdown = $result['data']['items_breakdown'] ?? [];
            $branchId = (int) $request->branch_id;
            $itemsSummary = [];

            foreach ($request->items as $item) {
                $availabilityError = $this->catalogAvailabilityService->validateOrderLineForNewOrder(
                    $branchId,
                    (int) $item['piece_id'],
                    (int) $item['service_id'],
                    $item['additional_service_ids'] ?? [],
                    $lang
                );
                if ($availabilityError !== null) {
                    return errorResponse($availabilityError, 400);
                }

                $piece = $pieces->firstWhere('id', $item['piece_id']);
                $service = $piece->services->firstWhere('id', $item['service_id']);

                if (! $service) {
                    $pieceName = \App\Support\OrderItemDisplayNames::pieceName($piece, (int) $branchId, $lang);

                    return errorResponse(__('order.service_not_available', ['piece_name' => $pieceName]), 400);
                }

                $servicePiecePrice = (float) $service->getPriceForPieceAtBranch($piece->id, $branchId);

                $additionalServicesSummary = [];
                $additionalServicesTotal = 0.0;
                if (! empty($item['additional_service_ids'])) {
                    $uniqueAdditionalServiceIds = array_unique($item['additional_service_ids']);
                    foreach ($uniqueAdditionalServiceIds as $additionalServiceId) {
                        $additionModel = \Modules\Service\Models\ServiceAddition::find($additionalServiceId);
                        if ($additionModel) {
                            $additionalPrice = (float) $additionModel->getPriceForPieceAtBranch($piece->id, $branchId);
                            $additionalServicesTotal += $additionalPrice;
                            $additionalServicesSummary[] = \App\Support\OrderItemDisplayNames::additionalServiceLine(
                                $additionModel,
                                (int) $branchId,
                                $lang,
                                $additionalPrice
                            );
                        }
                    }
                }

                $unitPrice = $servicePiecePrice + $additionalServicesTotal;
                $quantity = (int) $item['quantity'];
                for ($i = 0; $i < $quantity; $i++) {
                    $itemsSummary[] = $this->formatOrderLineItem(
                        $piece,
                        $service,
                        $servicePiecePrice,
                        $additionalServicesSummary,
                        $additionalServicesTotal,
                        1,
                        $unitPrice,
                        $lang,
                        (int) $branchId
                    );
                }
            }

            $deliveryFees = $this->computeDeliveryFees(
                $pickupAtVendor,
                $deliveryAtVendor,
                $branch,
                $vendor,
                $pickupAddress,
                $deliveryAddress
            );
            if ($deliveryFees instanceof JsonResponse) {
                return $deliveryFees;
            }

            if ($appliedDiscount && $discountItemsBreakdown !== []) {
                $rechecked = $this->discountService->evaluateKnownOrderDiscount(
                    $appliedDiscount,
                    $discountItemsBreakdown,
                    (float) $totalAmount,
                    (int) $user->id,
                    (int) $vendorId,
                    $branchId,
                    (float) $deliveryFees['delivery_fee'],
                    $deliveryAddress?->city ?? $pickupAddress?->city,
                    false,
                    $lang
                );
                $discountAmount = (float) $rechecked['discount_amount'];
                $deliveryDiscountAmount = (float) ($rechecked['delivery_discount_amount'] ?? 0);
            }

            $pricingSummary = $this->buildOrderPricingSummary(
                (float) $totalAmount,
                (float) $discountAmount,
                (float) $deliveryDiscountAmount,
                $deliveryFees,
                $pickupAtVendor,
                $deliveryAtVendor
            );

            return successResponse([
                'have_coupon' => true,
                'summary' => array_merge([
                    'items' => $itemsSummary,
                    'items_count' => count($itemsSummary),
                ], $pricingSummary),
                'delivery_info' => [
                    'pickup_address' => $pickupAddress ? [
                        'id' => $pickupAddress->id,
                        'address' => $pickupAddress->address_text ?? $pickupAddress->street_name,
                        'latitude' => $pickupAddress->latitude ? (float) $pickupAddress->latitude : null,
                        'longitude' => $pickupAddress->longitude ? (float) $pickupAddress->longitude : null,
                    ] : null,
                    'delivery_address' => $deliveryAddress ? [
                        'id' => $deliveryAddress->id,
                        'address' => $deliveryAddress->address_text ?? $deliveryAddress->street_name,
                        'latitude' => $deliveryAddress->latitude ? (float) $deliveryAddress->latitude : null,
                        'longitude' => $deliveryAddress->longitude ? (float) $deliveryAddress->longitude : null,
                    ] : null,
                    'branch_location' => $branch->getApiLocation($lang),
                ],
                'discount' => [
                    'code' => $appliedDiscount->code,
                    'name' => $appliedDiscount->name,
                    'type' => $appliedDiscount->type,
                    'value' => (float) $appliedDiscount->value,
                    'discount_amount' => (float) $discountAmount,
                ],
            ], $result['message']);
        } catch (\Exception $e) {
            return serverErrorResponse(__('order.failed_to_calculate').': '.$e->getMessage());
        }
    }

    /**
     * Rate order
     */
    public function rateOrder(Request $request, int $order_id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        // Get language from current locale (set by middleware)
        $lang = app()->getLocale();

        $order = Order::where('client_id', $user->id)->find($order_id);

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        if (! in_array($order->status, array_map(fn ($s) => $s->value, OrderStatus::completedStatuses()))) {
            return errorResponse(__('order.can_only_rate_completed'), 400);
        }

        if ($order->rating) {
            return errorResponse(__('order.order_already_rated'), 400);
        }

        $reviewText = $request->input('review') ?? $request->input('comment');

        $order->update([
            'rating' => $request->rating,
            'review' => $reviewText,
        ]);

        // Update vendor rating (orders link to vendor via branch_id → branches.vendor_id)
        $vendor = $order->vendor;
        if ($vendor) {
            $vendorRatingScope = function ($q) use ($vendor) {
                $q->whereHas('branch', function ($b) use ($vendor) {
                    $b->where('vendor_id', $vendor->id);
                })->whereNotNull('rating');
            };
            $avgRating = Order::query()->tap($vendorRatingScope)->avg('rating');
            $totalReviews = Order::query()->tap($vendorRatingScope)->count();

            $vendor->update([
                'rating' => round((float) ($avgRating ?? 0), 2),
                'total_reviews' => $totalReviews,
            ]);
        }

        return successResponse([
            'order_id' => $order->id,
            'rating' => $order->rating,
            'review' => $order->review,
        ], __('order.order_rated'));
    }

    /**
     * Delete order
     *
     * @param  Request  $request
     * @param  int  $order_id
     * @return JsonResponse
     */
    // REMOVED: destroy() function - Use cancelOrder() instead for proper order cancellation
    // Orders should never be deleted, only cancelled for audit trail purposes

    /**
     * Generate unique order number
     * Handles race conditions by using database lock and retry mechanism
     */
    private function generateUniqueOrderNumber(): string
    {
        $datePrefix = date('Ymd');
        $maxRetries = 10;
        $retry = 0;

        while ($retry < $maxRetries) {
            try {
                $nextSequence = DB::transaction(function () use ($datePrefix) {
                    return $this->getMaxReservedOrderSequenceForDate($datePrefix) + 1;
                });

                $orderNumber = 'ORD-'.$datePrefix.'-'.str_pad($nextSequence, 5, '0', STR_PAD_LEFT);

                if (! $this->isOrderNumberReserved($orderNumber)) {
                    return $orderNumber;
                }

                $retry++;
                usleep(10000);
            } catch (\Exception $e) {
                $retry++;

                if ($retry >= $maxRetries) {
                    $timestamp = (int) (microtime(true) * 1000);

                    return 'ORD-'.$datePrefix.'-'.str_pad($timestamp % 100000, 5, '0', STR_PAD_LEFT);
                }
                usleep(10000);
            }
        }

        $timestamp = (int) (microtime(true) * 1000);

        return 'ORD-'.$datePrefix.'-'.str_pad($timestamp % 100000, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Highest ORD-{date}-##### sequence already used today (orders, pending checkout, payments).
     */
    private function getMaxReservedOrderSequenceForDate(string $datePrefix): int
    {
        $pattern = "ORD-{$datePrefix}-%";
        $maxSequence = 0;

        $candidates = [
            Order::where('order_number', 'like', $pattern)
                ->lockForUpdate()
                ->orderBy('order_number', 'desc')
                ->value('order_number'),
            PaymentTransaction::where('transaction_id', 'like', $pattern)
                ->orderBy('transaction_id', 'desc')
                ->value('transaction_id'),
        ];

        foreach ($candidates as $orderNumber) {
            $sequence = $this->parseOrderNumberSequence($orderNumber, $datePrefix);
            if ($sequence !== null) {
                $maxSequence = max($maxSequence, $sequence);
            }
        }

        PendingOrder::where('order_data->order_number', 'like', $pattern)
            ->lockForUpdate()
            ->select(['order_data'])
            ->chunkById(100, function ($pendingOrders) use ($datePrefix, &$maxSequence) {
                foreach ($pendingOrders as $pendingOrder) {
                    $sequence = $this->parseOrderNumberSequence(
                        $pendingOrder->order_data['order_number'] ?? null,
                        $datePrefix
                    );
                    if ($sequence !== null) {
                        $maxSequence = max($maxSequence, $sequence);
                    }
                }
            });

        return $maxSequence;
    }

    private function parseOrderNumberSequence(?string $orderNumber, string $datePrefix): ?int
    {
        if ($orderNumber === null || $orderNumber === '') {
            return null;
        }

        $pattern = '/^ORD-'.preg_quote($datePrefix, '/').'-(\d+)$/';
        if (! preg_match($pattern, $orderNumber, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function isOrderNumberReserved(string $orderNumber): bool
    {
        if (Order::where('order_number', $orderNumber)->exists()) {
            return true;
        }

        if (PaymentTransaction::where('transaction_id', $orderNumber)->exists()) {
            return true;
        }

        return PendingOrder::where('order_data->order_number', $orderNumber)->exists();
    }

    /**
     * Get Payfort payment option based on payment method
     * Maps internal payment methods to Payfort payment_option values
     */
    private function getPayfortPaymentOption(string $paymentMethod): ?string
    {
        // Use PaymentMethod enum if available, otherwise fallback to direct mapping
        try {
            $method = PaymentMethod::from($paymentMethod);

            return $method->getPayfortPaymentOption();
        } catch (\ValueError $e) {
            // Fallback for backward compatibility
            return match ($paymentMethod) {
                'visa' => 'VISA',
                'mastercard' => 'MASTERCARD',
                'mada' => 'MADA',
                'stc_pay' => 'STCPAY',
                default => null,
            };
        }
    }

    /**
     * Split order lines into accepted / rejected / modified lists.
     * Multi-service piece lines stay one entry per piece (services joined),
     * split only when vendor statuses differ within the same piece.
     */
    private function categorizePendingApprovalItems(Order $order, string $lang): array
    {
        $categorized = \Modules\Order\Support\PendingApprovalItemCategorizer::categorize(
            $order,
            $lang,
            fn ($path) => $path ? $this->uploadFilesService->getFullUrl($path) : null
        );

        // The categorizer's entries carry only piece_name (a plain string), never the
        // piece's own id/icon, so any rejected-list entry rendered a generic
        // placeholder icon instead of the actual piece icon shown everywhere else.
        // Look the piece back up from the first order_item id in each entry.
        $branchId = (int) ($order->branch_id ?? 0);
        $pieceForIds = function (array $ids) use ($order) {
            $firstId = collect($ids)->first();

            return $firstId ? $order->items->firstWhere('id', $firstId)?->piece : null;
        };
        $withPiece = function (array $item) use ($pieceForIds, $branchId, $lang) {
            $piece = $pieceForIds($item['ids'] ?? []);
            $item['piece'] = [
                'id' => $piece?->id,
                'name' => $piece
                    ? \App\Support\OrderItemDisplayNames::pieceName($piece, $branchId, $lang)
                    : ($item['piece_name'] ?? 'Unknown'),
                'icon' => \App\Support\OrderItemDisplayNames::pieceIconUrl($piece),
            ];

            return $item;
        };

        $categorized['rejected'] = collect($categorized['rejected'])->map($withPiece)->values()->all();

        // An otherwise-accepted item can still have one or more of its additional
        // services rejected — categorize() already attaches those as
        // `rejected_services` on the accepted entry, but its `rejected` bucket only
        // covers whole-item rejections. The client's rejected list is expected to
        // surface every rejection, so add a compact entry per such item here too:
        // piece + just the declined addition(s) + their price (the item itself
        // stays in `accepted` at its correct, accepted-only price).
        $partiallyRejected = collect($categorized['accepted'])
            ->filter(fn (array $item) => ($item['rejected_services'] ?? []) !== [])
            ->map(function (array $item) use ($withPiece) {
                $rejectedServices = collect($item['rejected_services']);

                return $withPiece([
                    'id' => $item['id'] ?? null,
                    'ids' => $item['ids'] ?? [],
                    'piece_name' => $item['piece_name'] ?? 'Unknown',
                    'service_name' => $rejectedServices->pluck('name')->filter()->implode('، '),
                    'services' => $rejectedServices->map(fn (array $row) => [
                        'id' => $row['id'] ?? null,
                        'name' => $row['name'] ?? '',
                        'price' => (float) ($row['price'] ?? 0),
                    ])->values()->all(),
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => 0.0,
                    'total_price' => round(
                        $rejectedServices->sum(fn (array $row) => (float) ($row['price'] ?? 0) * (int) ($row['quantity'] ?? 1)),
                        2
                    ),
                    'vendor_notes' => null,
                    'note' => $item['note'] ?? null,
                    'description' => $item['description'] ?? null,
                    'image' => $item['image'] ?? null,
                    'additional_services' => [],
                    'status' => 'rejected',
                ]);
            })
            ->values()
            ->all();

        $categorized['rejected'] = array_values(array_merge($categorized['rejected'], $partiallyRejected));

        return $categorized;
    }

    /**
     * Pickup and delivery at branch (no driver).
     */
    private function isVendorSelfServiceOrder(Order $order): bool
    {
        return (bool) $order->pickup_at_vendor && (bool) $order->delivery_at_vendor;
    }

    /**
     * Statuses trackable for branch pickup/delivery orders (no driver).
     *
     * @return list<string>
     */
    private function vendorSelfServiceTrackingStatuses(): array
    {
        return [
            OrderStatus::CONFIRMED->value,
            OrderStatus::PAYMENT_CONFIRMED->value,
            OrderStatus::PICKED_UP->value,
            OrderStatus::DELIVERED_TO_BRANCH->value,
            OrderStatus::WAITING_CLIENT_RECEIPT->value,
            OrderStatus::COMPLETED->value,
            OrderStatus::DELIVERED->value,
        ];
    }

    private function transOrder(string $key, string $lang, array $replace = []): string
    {
        return __('order.'.$key, $replace, $lang);
    }

    /**
     * Title and message when client picks up/delivers at the laundry (no driver).
     *
     * @return array{0: string, 1: string}
     */
    private function getVendorSelfServiceNotificationTexts(string $status, string $lang): array
    {
        return match ($status) {
            OrderStatus::PAYMENT_CONFIRMED->value, OrderStatus::CONFIRMED->value => [
                $this->transOrder('on_the_way_self_go_to_laundry_title', $lang),
                $this->transOrder('on_the_way_self_go_to_laundry_message', $lang),
            ],
            OrderStatus::PICKED_UP->value, OrderStatus::DELIVERED_TO_BRANCH->value => [
                $this->transOrder('on_the_way_self_delivered_to_branch_title', $lang),
                $this->transOrder('on_the_way_self_delivered_to_branch_message', $lang),
            ],
            OrderStatus::COMPLETED->value, OrderStatus::DELIVERED->value => [
                $this->transOrder('on_the_way_self_ready_pickup_title', $lang),
                $this->transOrder('on_the_way_self_ready_pickup_message', $lang),
            ],
            default => [
                $this->transOrder('on_the_way_self_tracking_title', $lang),
                $this->transOrder('on_the_way_self_tracking_message', $lang),
            ],
        };
    }

    /**
     * Title and message when client must confirm actual handoff.
     *
     * @param  array{handoff_type: string, direction: string, confirm_label: string}  $context
     * @return array{0: string, 1: string}
     */
    private function getHandoffNotificationTexts(array $context, string $lang): array
    {
        return match ($context['handoff_type']) {
            'give_to_driver' => [
                $this->transOrder('on_the_way_handoff_give_driver_title', $lang),
                $this->transOrder('on_the_way_handoff_give_driver_message', $lang),
            ],
            'give_to_laundry' => [
                $this->transOrder('on_the_way_handoff_give_laundry_title', $lang),
                $this->transOrder('on_the_way_handoff_give_laundry_message', $lang),
            ],
            'receive_from_driver' => [
                $this->transOrder('on_the_way_handoff_receive_driver_title', $lang),
                $this->transOrder('on_the_way_handoff_receive_driver_message', $lang),
            ],
            'receive_from_laundry' => [
                $this->transOrder('on_the_way_handoff_receive_laundry_title', $lang),
                $this->transOrder('on_the_way_handoff_receive_laundry_message', $lang),
            ],
            default => [
                $this->transOrder('on_the_way_handoff_default_title', $lang),
                $context['confirm_label'] ?? $this->transOrder('on_the_way_confirm', $lang),
            ],
        };
    }

    /**
     * Title and message for on-the-way tracking card by order status.
     *
     * @return array{0: string, 1: string}
     */
    private function getOnTheWayNotificationTexts(string $status, string $lang, ?float $distanceKm): array
    {
        $distanceFormatted = $distanceKm !== null ? round($distanceKm, 1) : null;

        return match ($status) {
            OrderStatus::DRIVER_PICKUP_ASSIGNED->value,
            OrderStatus::DRIVER_PICKUP_ACCEPTED->value => [
                $this->transOrder('on_the_way_pickup_driver_assigned_title', $lang),
                $this->transOrder('on_the_way_pickup_driver_assigned_message', $lang),
            ],
            OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
            OrderStatus::DRIVER_DELIVERY_ACCEPTED->value => [
                $this->transOrder('on_the_way_delivery_driver_assigned_title', $lang),
                $this->transOrder('on_the_way_delivery_driver_assigned_message', $lang),
            ],
            OrderStatus::DELIVERED_TO_BRANCH->value => [
                $this->transOrder('on_the_way_delivered_to_branch_title', $lang),
                $this->transOrder('on_the_way_delivered_to_branch_message', $lang),
            ],
            OrderStatus::PICKED_UP->value => [
                $this->transOrder('on_the_way_picked_up_title', $lang),
                $this->transOrder('on_the_way_picked_up_message', $lang),
            ],
            OrderStatus::ON_WAY_TO_PICKUP->value => [
                $this->transOrder('on_the_way_on_way_pickup_title', $lang),
                $distanceFormatted !== null
                    ? $this->transOrder('on_the_way_on_way_pickup_message_distance', $lang, ['distance' => $distanceFormatted])
                    : $this->transOrder('on_the_way_on_way_pickup_message', $lang),
            ],
            OrderStatus::ON_WAY_TO_DELIVERY->value => [
                $this->transOrder('on_the_way_on_way_delivery_title', $lang),
                $distanceFormatted !== null
                    ? $this->transOrder('on_the_way_on_way_delivery_message_distance', $lang, ['distance' => $distanceFormatted])
                    : $this->transOrder('on_the_way_on_way_delivery_message', $lang),
            ],
            OrderStatus::WAITING_CLIENT_RECEIPT->value => [
                $this->transOrder('on_the_way_waiting_client_receipt_title', $lang),
                $this->transOrder('on_the_way_waiting_client_receipt_message', $lang),
            ],
            OrderStatus::DELIVERED->value => [
                $this->transOrder('on_the_way_delivered_title', $lang),
                $this->transOrder('on_the_way_delivered_message', $lang),
            ],
            default => [
                $this->transOrder('on_the_way_default_title', $lang),
                $this->transOrder('on_the_way_default_message', $lang),
            ],
        };
    }

    private function shouldUseDriverTrackingTexts(Order $order): bool
    {
        if ((bool) $order->pickup_at_vendor && (bool) $order->delivery_at_vendor) {
            return false;
        }

        $status = $order->status;

        if (
            ! (bool) $order->delivery_at_vendor
            && $order->delivery_driver_id
            && in_array($status, array_merge(
                OrderStatus::onTheWayTrackingStatusValues(),
                OrderStatus::clientDeliveryVisitResponseStatusValues()
            ), true)
        ) {
            return true;
        }

        if (
            ! (bool) $order->pickup_at_vendor
            && $order->pickup_driver_id
            && in_array($status, array_merge(
                OrderStatus::onTheWayTrackingStatusValues(),
                OrderStatus::clientPickupVisitResponseStatusValues()
            ), true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getVisitNotificationTexts(array $visitContext, string $lang): array
    {
        return match ($visitContext['visit_type'] ?? null) {
            'pickup' => [
                $this->transOrder('on_the_way_pickup_driver_assigned_title', $lang),
                $this->transOrder('on_the_way_pickup_driver_assigned_message', $lang),
            ],
            'delivery' => [
                $this->transOrder('on_the_way_on_way_delivery_title', $lang),
                $this->transOrder('on_the_way_on_way_delivery_message', $lang),
            ],
            'branch_dropoff' => [
                $this->transOrder('on_the_way_handoff_give_laundry_title', $lang),
                $this->transOrder('on_the_way_handoff_give_laundry_message', $lang),
            ],
            'branch_pickup' => [
                $this->transOrder('on_the_way_self_ready_pickup_title', $lang),
                $this->transOrder('on_the_way_self_ready_pickup_message', $lang),
            ],
            'receipt' => [
                $this->transOrder('on_the_way_waiting_client_receipt_title', $lang),
                $this->transOrder('on_the_way_waiting_client_receipt_message', $lang),
            ],
            default => [
                $this->transOrder('on_the_way_handoff_default_title', $lang),
                $visitContext['confirm_label'] ?? $this->transOrder('on_the_way_confirm', $lang),
            ],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getDriverTrackingNotificationTexts(Order $order, string $lang, ?float $distanceKm): array
    {
        $distanceFormatted = $distanceKm !== null ? round($distanceKm, 1) : null;

        if (
            ! (bool) $order->delivery_at_vendor
            && $order->client_delivery_visit_confirmed_at
            && in_array($order->status, [
                OrderStatus::ON_WAY_TO_DELIVERY->value,
                OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
                OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
            ], true)
        ) {
            return [
                $this->transOrder('on_the_way_delivery_visit_confirmed_title', $lang),
                $distanceFormatted !== null
                    ? $this->transOrder('on_the_way_delivery_visit_confirmed_message_distance', $lang, ['distance' => $distanceFormatted])
                    : $this->transOrder('on_the_way_delivery_visit_confirmed_message', $lang),
            ];
        }

        return $this->getOnTheWayNotificationTexts($order->status, $lang, $distanceKm);
    }

    private function formatOnTheWayTimeRemaining(int $estimatedTimeMinutes, string $lang): string
    {
        if ($estimatedTimeMinutes < 1) {
            return $this->transOrder('on_the_way_time_less_than_minute', $lang);
        }

        if ($estimatedTimeMinutes < 60) {
            return $this->transOrder('on_the_way_time_minutes', $lang, ['minutes' => $estimatedTimeMinutes]);
        }

        $hours = (int) floor($estimatedTimeMinutes / 60);
        $minutes = $estimatedTimeMinutes % 60;

        if ($minutes > 0) {
            return $this->transOrder('on_the_way_time_hours_minutes', $lang, [
                'hours' => $hours,
                'minutes' => $minutes,
            ]);
        }

        return $this->transOrder('on_the_way_time_hours', $lang, ['hours' => $hours]);
    }

    /**
     * Card fields for on-the-way / pending-approval from the order piece line:
     * name = piece name, description = item note, image = uploaded item image.
     *
     * @return array{name: ?string, description: ?string, image: ?string}
     */
    private function getOnTheWayCardPayload(Order $order, string $lang): array
    {
        $branchId = (int) ($order->branch_id ?? 0);
        $items = $order->items ?? collect();

        if ($items->isEmpty()) {
            return [
                'name' => null,
                'description' => null,
                'image' => null,
            ];
        }

        // Prefer the piece line the client actually annotated (image / note).
        $featured = $items->first(fn ($item) => ! empty($item->images))
            ?? $items->first(fn ($item) => ! empty($item->notes))
            ?? $items->first();

        $groupItems = collect([$featured]);
        foreach (OrderItemGrouper::buckets($items) as $bucket) {
            if ($bucket->contains(fn ($item) => (int) $item->id === (int) $featured->id)) {
                $groupItems = $bucket;
                break;
            }
        }

        $primary = $groupItems->first() ?? $featured;

        $name = $primary?->piece
            ? \App\Support\OrderItemDisplayNames::pieceName($primary->piece, $branchId, $lang)
            : null;

        $note = $groupItems
            ->map(fn ($item) => $item->notes)
            ->first(fn ($value) => filled($value));

        $serviceNames = $groupItems
            ->map(function ($item) use ($branchId, $lang) {
                if (! $item->service) {
                    return null;
                }

                return \App\Support\OrderItemDisplayNames::serviceName($item->service, $branchId, $lang);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $description = $note
            ?: ($serviceNames !== [] ? implode($lang === 'ar' ? '، ' : ', ', $serviceNames) : null);

        $itemWithImage = $groupItems->first(fn ($item) => ! empty($item->images))
            ?? $items->first(fn ($item) => ! empty($item->images));

        $image = $itemWithImage?->images
            ? $this->uploadFilesService->getFullUrl($itemWithImage->images)
            : \App\Support\OrderItemDisplayNames::pieceIconUrl($primary?->piece);

        return [
            'name' => $name,
            'description' => $description,
            'image' => $image,
        ];
    }

    /**
     * Get order pending approval (vendor made modifications)
     */
    public function getPendingApproval(Request $request, ?int $orderId = null): JsonResponse
    {
        $client = $request->user();
        $lang = app()->getLocale();

        $query = Order::where('client_id', $client->id)
            ->whereIn('status', [
                OrderStatus::BRANCH_REVIEW->value,
                OrderStatus::WAITING_PAYMENT->value,
            ]);

        if ($orderId) {
            $query->where('id', $orderId);
        }

        $orders = $query->with([
            'items.piece',
            'items.service',
            'items.additionalServicesPivot.serviceAddition',
            'branch.vendor',
            'vendor',
        ])
            ->orderBy('updated_at', 'desc')
            ->get();

        if ($orderId && $orders->isEmpty()) {
            return errorResponse(__('order.order_not_found'), 404);
        }

        $ordersData = $orders->map(function ($order) use ($lang) {
            $categorized = $this->categorizePendingApprovalItems($order, $lang);
            $acceptedItems = $categorized['accepted'];
            $rejectedItems = $categorized['rejected'];
            $modifiedItems = $categorized['modified'];

            // Determine required action
            $requiredAction = null;
            $actionDescription = null;

            if ($order->status === OrderStatus::BRANCH_REVIEW->value) {
                $requiredAction = 'approve_or_reject';
                $actionDescription = $lang === 'ar'
                    ? 'يجب الموافقة على التعديلات أو رفضها'
                    : 'You need to approve or reject the modifications';
            } elseif ($order->status === OrderStatus::WAITING_PAYMENT->value && ! $order->isCashOnDelivery()) {
                $requiredAction = 'complete_payment';
                $actionDescription = $lang === 'ar'
                    ? 'يجب إتمام الدفع'
                    : 'You need to complete the payment';
            }

            $cardTitle = $order->status === OrderStatus::BRANCH_REVIEW->value
                ? ($lang === 'ar' ? 'تم تحديث طلبك' : 'Your order has been updated')
                : ($lang === 'ar' ? 'الدفع مطلوب لإكمال الطلب' : 'Payment required to complete your order');
            $cardDescription = $order->status === OrderStatus::BRANCH_REVIEW->value
                ? ($lang === 'ar'
                    ? 'قامت المغسلة بمراجعة الطلب، وكانت النتيجة كالتالي:'
                    : 'The laundry has reviewed the order. Here is the result:')
                : ($actionDescription ?? ($lang === 'ar' ? 'يجب إتمام الدفع' : 'You need to complete the payment'));
            $cardPayload = $this->getOnTheWayCardPayload($order, $lang);

            return array_merge([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_label' => OrderStatus::fromString($order->status)?->localizedLabel($order->payment_method, $order->status === OrderStatus::COMPLETED->value && ! $order->client_delivery_handoff_at, (bool) $order->delivery_at_vendor) ?? $order->status,
                'title' => $cardTitle,
                'message' => $cardDescription,
                ...$cardPayload,
                'required_action' => $requiredAction,
                'action_description' => $actionDescription,
                'vendor' => [
                    'id' => $order->vendor?->id,
                    'name' => $order->vendor ? $order->vendor->getTranslatedName($lang) : null,
                    'logo' => $order->vendor ? $this->uploadFilesService->getFullUrl($order->vendor->logo) : null,
                ],
                'branch' => [
                    'id' => $order->branch?->id,
                    'name' => $order->branch ? (
                        method_exists($order->branch, 'getTranslation')
                        ? $order->branch->getTranslation('name', $lang)
                        : $order->branch->name
                    ) : null,
                    'delivery_price_per_km' => (float) (
                        $order->branch?->vendor?->delivery_price_per_km
                        ?? $order->vendor?->delivery_price_per_km
                        ?? 0
                    ),
                ],
                'vendor_reviewed' => $order->vendor_reviewed,
                'vendor_review_notes' => $order->vendor_review_notes,
                'vendor_reviewed_at' => $order->vendor_reviewed_at?->toISOString(),
                'client_approved' => $order->client_approved,
                'client_approved_at' => $order->client_approved_at?->toISOString(),
                'pricing' => [
                    'original_total_amount' => (float) $order->original_total_amount,
                    'original_final_amount' => (float) $order->original_final_amount,
                    'new_total_amount' => (float) $order->total_amount,
                    'new_discount_amount' => (float) $order->discount_amount,
                    'new_tax_amount' => (float) $order->tax_amount,
                    'new_delivery_fee' => (float) $order->delivery_fee,
                    'new_final_amount' => (float) $order->final_amount,
                    'price_difference' => (float) ($order->final_amount - $order->original_final_amount),
                ],
                'items_summary' => [
                    'total_items' => $order->items->count(),
                    'accepted_count' => count($acceptedItems),
                    'rejected_count' => count($rejectedItems),
                    'modified_count' => count($modifiedItems),
                ],
                'accepted_items' => $acceptedItems,
                'rejected_items' => $rejectedItems,
                'modified_items' => $modifiedItems,
                'created_at' => $order->created_at->toISOString(),
            ], $order->clientVisitResponseFields());
        });

        $message = $lang === 'ar'
            ? 'تم استرجاع الطلبات التي تحتاج إجراء منك'
            : 'Orders requiring your action retrieved successfully';

        return successResponse([
            'orders' => $ordersData,
            'total_count' => $ordersData->count(),
        ], $message);
    }

    /**
     * Send reminder notification to laundry
     */
    public function sendReminderNotification(Request $request, int $orderId): JsonResponse
    {
        $client = $request->user();
        $lang = app()->getLocale();

        $order = Order::with(['vendor', 'branch'])
            ->where('client_id', $client->id)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        // Check if order is in a status that requires vendor action
        $validStatuses = [
            OrderStatus::PENDING->value,
            OrderStatus::BRANCH_REVIEW->value,
        ];

        if (! in_array($order->status, $validStatuses)) {
            return errorResponse(__('order.cannot_send_reminder'), 400);
        }

        try {
            app(\App\Services\OrderNotificationService::class)->sendToVendorBranch(
                $order,
                'تذكير بطلب جديد',
                'Reminder: New Order',
                "تذكير: لديك طلب جديد رقم #{$order->order_number} في انتظار المراجعة",
                "Reminder: You have a new order #{$order->order_number} waiting for review",
                'order_reminder',
            );

            return successResponse([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reminder_sent_at' => now()->toISOString(),
            ], __('order.reminder_sent_successfully'));

        } catch (\Exception $e) {
            return serverErrorResponse(__('order.failed_to_send_reminder'));
        }
    }

    /**
     * Complete payment for order after vendor approval
     * Handles all payment methods: cash, wallet, and card payments
     */
    public function completePayment(Request $request, int $orderId): JsonResponse
    {
        $resolvedMethod = \Modules\Payment\Models\PaymentMethod::resolveFromClientInput(
            $request->input('payment_method', $request->input('payment_methods'))
        );
        if ($resolvedMethod !== null) {
            $request->merge(['payment_method' => $resolvedMethod]);
        }

        $validator = Validator::make($request->all(), [
            'payment_method' => ['required', 'string', Rule::in(PaymentMethod::values())],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $client = $request->user();
        $lang = app()->getLocale();

        $order = Order::with(['items', 'vendor', 'branch', 'client'])
            ->where('client_id', $client->id)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        if ($order->status !== OrderStatus::WAITING_PAYMENT->value) {
            return errorResponse(__('order.order_not_waiting_payment'), 400);
        }

        // Check if client has approved modifications (if any)
        if (! $order->client_approved && $order->vendor_reviewed) {
            return errorResponse(__('order.must_approve_modifications_first'), 400);
        }

        DB::beginTransaction();
        try {
            // Same conditional alias as the split/surcharge paths: Payfort has no
            // generic card option and needs a concrete brand, Moyasar accepts
            // "credit_card" as-is (see OrderPaymentService::normalizePaymentMethodAlias()).
            $paymentMethod = $this->orderPaymentService->normalizePaymentMethodsInput($request->payment_method)[0]
                ?? $request->payment_method;

            // Handle different payment methods
            if ($paymentMethod === PaymentMethod::CASH_ON_DELIVERY->value) {
                $order->update(['payment_method' => $paymentMethod]);

                $statusService = app(\App\Services\OrderStatusService::class);
                $statusService->transitionTo($order, OrderStatus::PAYMENT_CONFIRMED, [
                    'notes' => 'Payment method set to cash on delivery',
                    'changed_by' => $client->id,
                    'actor_type' => 'client',
                    'actor_id' => $client->id,
                ]);

                DB::commit();

                return successResponse(array_merge([
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'status_label' => OrderStatus::fromString($order->status)?->localizedLabel($order->payment_method, $order->status === OrderStatus::COMPLETED->value && ! $order->client_delivery_handoff_at, (bool) $order->delivery_at_vendor) ?? $order->status,
                    'payment_method' => $paymentMethod,
                    'final_amount' => (float) $order->final_amount,
                ], $order->clientVisitResponseFields()), __('order.payment_completed_successfully'));

            } elseif ($paymentMethod === PaymentMethod::Nathefah_WALLET->value) {
                // Check wallet balance
                $walletBalance = app(OrderPaymentService::class)->availableWalletBalance((int) $client->id);

                if ($walletBalance < $order->final_amount) {
                    DB::rollBack();

                    return errorResponse(__('order.insufficient_wallet_balance'), 400);
                }

                // Deduct from wallet
                DB::table('clients')
                    ->where('id', $client->id)
                    ->decrement('wallet_balance', $order->final_amount);

                // Create wallet transaction
                DB::table('wallet_transactions')->insert([
                    'client_id' => $client->id,
                    'type' => 'debit',
                    'amount' => $order->final_amount,
                    'payment_method' => $paymentMethod,
                    'description' => 'Order #'.$order->order_number.' payment',
                    'order_id' => $order->id,
                    'transaction_id' => 'ORDER-'.$order->id.'-'.time(),
                    'status' => 'completed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create payment transaction record
                PaymentTransaction::create([
                    'order_id' => $order->id,
                    'gateway' => 'wallet',
                    'transaction_id' => 'WALLET-'.$order->id.'-'.time(),
                    'amount' => $order->final_amount,
                    'currency' => 'SAR',
                    'status' => 'completed',
                    'payment_method' => $paymentMethod,
                    'customer_email' => $client->email,
                    'customer_name' => $client->name,
                    'customer_phone' => $client->phone,
                    'paid_at' => now(),
                ]);

                $order->update(['payment_method' => $paymentMethod]);

                $statusService = app(\App\Services\OrderStatusService::class);
                $statusService->transitionTo($order, OrderStatus::PAYMENT_CONFIRMED, [
                    'notes' => 'Payment completed via wallet',
                    'changed_by' => $client->id,
                    'actor_type' => 'client',
                    'actor_id' => $client->id,
                ]);

                DB::commit();

                return successResponse(array_merge([
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->fresh()->status,
                    'status_label' => OrderStatus::fromString($order->fresh()->status)?->localizedLabel($order->payment_method, $order->fresh()->status === OrderStatus::COMPLETED->value && ! $order->fresh()->client_delivery_handoff_at, (bool) $order->delivery_at_vendor) ?? $order->fresh()->status,
                    'payment_method' => $paymentMethod,
                    'final_amount' => (float) $order->final_amount,
                    'wallet_balance' => (float) ($walletBalance - $order->final_amount),
                ], $order->fresh()->clientVisitResponseFields()), __('order.payment_completed_successfully'));

            } else {
                $gateway = \App\Enums\PaymentMethod::getGatewayName($paymentMethod);
                $paymentOption = \App\Enums\PaymentMethod::from($paymentMethod)->getPayfortPaymentOption();

                // Update order with payment method first
                $order->update([
                    'payment_method' => $paymentMethod,
                ]);

                // Check if order is already paid
                $existingPayment = PaymentTransaction::where('order_id', $order->id)
                    ->whereIn('status', ['completed', 'authorized', 'pending'])
                    ->first();

                if ($existingPayment && in_array($existingPayment->status, ['completed', 'authorized'])) {
                    DB::rollBack();

                    return errorResponse(__('order.order_already_paid'), 400);
                }

                // Initialize payment gateway
                $this->paymentService->setGateway($gateway);

                // Prepare customer data
                $customerEmail = $client->email ?? '';
                $customerName = $client->name ?? 'Customer';
                $customerPhone = $client->phone ?? '';

                if (! $customerEmail) {
                    DB::rollBack();

                    return errorResponse(__('order.customer_email_required'), 400);
                }

                // Create payment request with payment option
                $metadata = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'client_id' => $order->client_id,
                    'payment_option' => $paymentOption,
                ];

                $methodEnum = PaymentMethod::tryFrom($paymentMethod);

                $paymentRequest = new PaymentRequest(
                    amount: (float) $order->final_amount,
                    currency: 'SAR',
                    orderId: $order->order_number,
                    customerEmail: $customerEmail,
                    customerName: $customerName,
                    customerPhone: $customerPhone,
                    // Use the explicit clean path: route('checkout.callback') resolves to
                    // a duplicate registration with a doubled '/v1/v1/' prefix that the
                    // gateway callback may be unable to reach. url() always yields
                    // https://<app_url>/api/v1/checkout/callback.
                    returnUrl: url('/api/v1/checkout/callback').'?order_id='.$order->id,
                    cancelUrl: url('/api/v1/checkout/cancel').'?order_id='.$order->id,
                    paymentOption: $paymentOption,
                    metadata: $metadata,
                    enableTokenization: $methodEnum?->supportsPayfortTokenization() ?? false,
                );

                // Initialize payment
                $response = $this->paymentService->initializePayment($paymentRequest);

                \Illuminate\Support\Facades\Log::channel('payment')->info('Checkout init: payment initialized', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'merchant_reference' => $response->transactionId,
                    'amount' => (float) $order->final_amount,
                    'payment_method' => $paymentMethod,
                    'payment_option' => $paymentOption,
                    'gateway' => $this->paymentService->getActiveGateway()?->getName(),
                    'return_url' => url('/api/v1/checkout/callback').'?order_id='.$order->id,
                    'init_success' => $response->isSuccessful(),
                    'init_status' => $response->status,
                    'init_message' => $response->message,
                ]);

                if ($response->isSuccessful()) {
                    if ($existingPayment) {
                        $existingPayment->update([
                            'transaction_id' => $response->transactionId,
                            'status' => $response->status ?? 'pending',
                            'payment_method' => $paymentMethod,
                            'response_data' => $response->data,
                        ]);
                    } else {
                        PaymentTransaction::create([
                            'order_id' => $order->id,
                            'gateway' => $this->paymentService->getActiveGateway()->getName(),
                            'transaction_id' => $response->transactionId,
                            'amount' => $order->final_amount,
                            'currency' => 'SAR',
                            'status' => $response->status ?? 'pending',
                            'payment_method' => $paymentMethod,
                            'customer_email' => $customerEmail,
                            'customer_name' => $customerName,
                            'customer_phone' => $customerPhone,
                            'response_data' => $response->data,
                        ]);
                    }

                    $activeGateway = $this->paymentService->getActiveGateway();
                    $paymentParams = $response->data['payment_params'] ?? null;
                    if (
                        $paymentMethod === 'stc_pay'
                        && $paymentParams !== null
                        && (($paymentParams['payment_option'] ?? null) !== 'STCPAY')
                    ) {
                        DB::rollBack();

                        return errorResponse(__('order.stc_pay_init_failed'), 500);
                    }
                    $paymentFormHtml = null;
                    if ($paymentParams && $activeGateway && method_exists($activeGateway, 'getPaymentFormHtml')) {
                        try {
                            /** @var mixed $activeGateway */
                            $paymentFormHtml = $activeGateway->getPaymentFormHtml($paymentParams);
                        } catch (\Exception $e) {
                            // Keep response usable even if form HTML generation fails
                        }
                    }

                    DB::commit();

                    return successResponse([
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'payment_method' => $paymentMethod,
                        'gateway' => $gateway,
                        'final_amount' => (float) $order->final_amount,
                        'requires_gateway' => true,
                        'transaction_id' => $response->transactionId,
                        'payment_url' => $response->paymentUrl,
                        'payment_params' => $paymentParams,
                        'payment_form_html' => $paymentFormHtml,
                        'payment_method_type' => $paymentMethod,
                        'redirect_instructions' => $response->redirectInstructions(),
                    ], __('order.proceed_to_payment_gateway'));
                }

                DB::rollBack();

                return errorResponse($response->message ?? __('order.payment_init_failed'), 400);
            }

        } catch (\Exception $e) {
            DB::rollBack();

            return serverErrorResponse(__('order.payment_failed'));
        }
    }

    /**
     * Send payment confirmation notification to vendor
     */
    private function sendPaymentConfirmationNotification(Order $order, string $lang): void
    {
        try {
            app(\App\Services\OrderNotificationService::class)->sendToVendorBranch(
                $order,
                'تم الدفع - ابدأ العمل',
                'Payment Completed - Start Work',
                "تم الدفع للطلب رقم #{$order->order_number}. يمكنك البدء في العمل على الطلب",
                "Payment completed for order #{$order->order_number}. You can start working on the order",
                'order_payment_completed',
                [
                    'payment_method' => $order->payment_method,
                    'final_amount' => $order->final_amount,
                ]
            );
        } catch (\Exception $e) {
        }
    }

    private function getOrderStatusAfterPayment(Order $order): string
    {
        return OrderStatus::PAYMENT_CONFIRMED->value;
    }

    /**
     * Get order payment status
     * Moved from OrderCheckoutController for better organization
     */
    public function getPaymentStatus(Request $request, int $orderId): JsonResponse
    {
        $client = $request->user();

        $order = Order::with(['latestPayment'])
            ->where('client_id', $client->id)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        // Sync with PayFort when any transaction is still pending/authorized but unsettled
        $hasUnsettled = PaymentTransaction::where('order_id', $order->id)
            ->whereIn('status', ['pending', 'authorized'])
            ->exists();

        if ($hasUnsettled) {
            try {
                app(\Modules\Payment\Http\Controllers\PaymentController::class)->syncOrderPayment($order);
                $order->refresh()->load('latestPayment');
            } catch (\Throwable $e) {
                // Return last known state if gateway sync fails
            }
        }

        $holds = $this->orderPaymentService->getOrderHolds($order);

        $transactions = PaymentTransaction::where('order_id', $order->id)
            ->orderByDesc('id')
            ->get();

        $latest = $transactions->first();

        if (! $latest) {
            $paymentStatus = $this->orderPaymentService->resolveClientPaymentStatus($order, null);

            return successResponse([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_status' => $order->status,
                'payment_status' => $paymentStatus,
                'payment_status_label' => \App\Support\PaymentStatusPresenter::label($paymentStatus),
                'is_paid' => $order->isPaid(),
                'final_amount' => (float) $order->final_amount,
                'holding' => $holds,
            ], $this->orderPaymentService->clientPaymentStatusMessage($paymentStatus));
        }

        $paymentStatus = $this->orderPaymentService->resolveClientPaymentStatus($order, $latest);
        $paidTotal = $this->orderPaymentService->paidTotal($order);

        $legs = \Modules\Order\Models\OrderPayment::where('order_id', $order->id)
            ->orderBy('sequence')
            ->get()
            ->map(fn ($leg) => [
                'payment_method' => $leg->payment_method,
                'amount' => (float) $leg->amount,
                'status' => $leg->status,
                'is_surcharge' => (bool) $leg->is_surcharge,
                'is_held' => $leg->status === \Modules\Order\Models\OrderPayment::STATUS_PENDING
                    && $leg->payment_method === PaymentMethod::Nathefah_WALLET->value,
            ]);

        return successResponse([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_status' => $order->status,
            'payment_status' => $paymentStatus,
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($paymentStatus),
            'is_paid' => $order->isPaid(),
            'fully_paid' => $this->orderPaymentService->isFullyPaid($order),
            'final_amount' => (float) $order->final_amount,
            'holding' => $holds,
            'transaction_id' => $latest->transaction_id,
            'gateway' => $latest->gateway,
            'amount' => (float) $latest->amount,
            'currency' => $latest->currency,
            'status' => $paymentStatus,
            'gateway_status' => $latest->status,
            'paid_at' => $latest->paid_at?->toISOString(),
            'payment_legs' => $legs,
            'transactions' => $transactions->map(fn ($tx) => [
                'transaction_id' => $tx->transaction_id,
                'status' => $this->orderPaymentService->normalizeGatewayPaymentStatus($tx->status, $paidTotal),
                'gateway_status' => $tx->status,
                'amount' => (float) $tx->amount,
                'authorized_amount' => $tx->authorized_amount ? (float) $tx->authorized_amount : null,
                'is_held' => $tx->status === 'authorized',
                'held_amount' => $tx->status === 'authorized'
                    ? (float) ($tx->authorized_amount ?: $tx->amount)
                    : 0.0,
                'payment_method' => $tx->payment_method,
                'paid_at' => $tx->paid_at?->toISOString(),
            ])->values(),
        ], $this->orderPaymentService->clientPaymentStatusMessage($paymentStatus));
    }

    /**
     * Delete/Cancel order
     */
    public function destroy(Request $request, int $order_id): JsonResponse
    {
        $user = $request->user();
        $accept = $request->header('Accept-Language', 'en');
        $lang = Str::contains(strtolower($accept), 'ar') ? 'ar' : 'en';

        // Set locale for translations
        $originalLocale = app()->getLocale();
        app()->setLocale($lang);

        $order = Order::where('client_id', $user->id)->find($order_id);

        if (! $order) {
            $message = __('order.order_not_found');
            app()->setLocale($originalLocale);

            return notFoundResponse($message);
        }

        // If order is already cancelled, delete it permanently
        if ($order->status === OrderStatus::CANCELLED->value) {
            // Delete related records first
            $order->items()->each(function ($item) {
                $item->additionalServicesPivot()->delete();
            });
            $order->items()->delete();
            $order->statusLogs()->delete();

            // Delete the order
            $order->delete();

            app()->setLocale($originalLocale);

            return successResponse(null, __('order.order_deleted_successfully'));
        }

        $statusService = app(\App\Services\OrderStatusService::class);
        if (! $statusService->canTransition($order, OrderStatus::CANCELLED)) {
            $message = __('order.order_cannot_cancel_status');
            app()->setLocale($originalLocale);

            return ErrorResponse::make($message, null, 400);
        }

        $statusService->transitionTo($order, OrderStatus::CANCELLED, [
            'notes' => 'Deleted by user',
            'reason' => 'Deleted by user',
            'changed_by' => $user->id,
            'actor_type' => 'client',
            'actor_id' => $user->id,
        ]);

        app()->setLocale($originalLocale);

        return successResponse([
            'order_id' => $order->id,
            'status' => OrderStatus::CANCELLED->value,
            'cancelled_at' => $order->cancelled_at->toISOString(),
        ], __('order.order_cancelled_success'));
    }

    /**
     * Get all orders on the way (on_way_to_pickup or on_way_to_delivery)
     */
    public function getOrdersOnTheWay(Request $request, ?int $orderId = null): JsonResponse
    {
        $user = $request->user();
        $lang = app()->getLocale();
        $orderId = $orderId ?: $request->get('track_order_id') ?: $request->get('order_id') ?: $request->get('id');

        $handoffService = app(\App\Services\ClientOrderHandoffService::class);
        $visitService = app(\App\Services\ClientOrderVisitService::class);
        $vendorHandoffService = app(\App\Services\VendorOrderHandoffService::class);
        $onTheWayStatuses = OrderStatus::clientDriverVisitTrackingStatusValues();

        $query = Order::with([
            'driver',
            'pickupAddress',
            'deliveryAddress',
            'branch',
            'items.piece',
            'items.service',
            'items.additionalServicesPivot.serviceAddition',
        ])
            ->where('client_id', $user->id)
            ->where(function ($builder) use ($onTheWayStatuses) {
                $builder->whereIn('status', $onTheWayStatuses)
                    ->orWhere('status', OrderStatus::BRANCH_REVIEW->value)
                    ->orWhere(function ($pendingBranchPickup) {
                        $pendingBranchPickup
                            ->whereIn('status', [OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::COMPLETED->value])
                            ->where('delivery_at_vendor', true)
                            ->whereNull('client_delivery_handoff_at');
                    });
            });

        if ($orderId) {
            $query->where('id', $orderId);
        }

        $orders = $query->orderBy('updated_at', 'desc')->get();
        if ($orderId && $orders->isEmpty()) {
            $order = Order::with([
                'driver',
                'pickupAddress',
                'deliveryAddress',
                'branch',
                'items.piece',
                'items.service',
                'items.additionalServicesPivot.serviceAddition',
            ])
                ->where('client_id', $user->id)
                ->where('id', $orderId)
                ->first();

            if (! $order) {
                return errorResponse(__('order.order_not_found'), 404);
            }

            $isCashOnDelivery = ($order->payment_method ?? null) === PaymentMethod::CASH_ON_DELIVERY->value;
            if ($order->status === OrderStatus::WAITING_PAYMENT->value && ($order->isPaid() || $isCashOnDelivery)) {
                $statusService = app(\App\Services\OrderStatusService::class);
                try {
                    $statusService->transitionTo($order, OrderStatus::PAYMENT_CONFIRMED, [
                        'notes' => $isCashOnDelivery
                            ? 'Auto: cash on delivery order does not require pre-delivery payment'
                            : 'Auto: payment already completed at checkout',
                        'changed_by' => $user->id,
                    ]);
                } catch (\App\Exceptions\InvalidStatusTransitionException) {
                }
                $order->refresh();
            }

            if (in_array($order->status, $onTheWayStatuses, true)) {
                $orders = collect([$order]);
            } else {
                $currentStatus = $order->status;
                $statusLabel = OrderStatus::tryFrom($currentStatus)?->localizedLabel($order->payment_method, $currentStatus === OrderStatus::COMPLETED->value && ! $order->client_delivery_handoff_at, (bool) $order->delivery_at_vendor) ?? $currentStatus;

                if ($currentStatus === OrderStatus::BRANCH_REVIEW->value) {
                    // Laundry reviewed with changes — client must approve/reject.
                    // Return on-the-way payload with review summary instead of 400.
                    $orders = collect([$order]);
                } else {
                    if ($currentStatus === OrderStatus::WAITING_PAYMENT->value) {
                        $isCashOnDelivery = ($order->payment_method ?? null) === PaymentMethod::CASH_ON_DELIVERY->value;
                        if (! $isCashOnDelivery) {
                            return errorResponse(__('order.order_waiting_payment_complete'), 400);
                        }
                    }

                    if ($handoffService->isPendingBranchPickupReceipt($order)) {
                        $order = $handoffService->repairInconsistentBranchPickupStatus($order);
                        $orders = collect([$order->fresh()]);
                    } elseif (
                        $this->isVendorSelfServiceOrder($order)
                        && in_array($currentStatus, $this->vendorSelfServiceTrackingStatuses(), true)
                    ) {
                        $orders = collect([$order]);
                    } elseif ($vendorHandoffService->isClientHandoffTrackable($order)) {
                        $orders = collect([$order]);
                    } elseif ($visitService->canRespond($order)) {
                        $orders = collect([$order]);
                    } elseif ($handoffService->canConfirmHandoff($order)) {
                        $orders = collect([$order]);
                    } elseif ($currentStatus === OrderStatus::PAYMENT_CONFIRMED->value) {
                        return errorResponse(__('order.order_payment_confirmed_driver_pending'), 400);
                    } else {
                        return errorResponse(__('order.order_not_on_the_way', ['status' => $statusLabel]), 400);
                    }
                }
            }
        }

        $ordersData = $orders->map(function ($order) use ($lang, $handoffService, $visitService, $vendorHandoffService) {
            $order = $handoffService->repairInconsistentBranchPickupStatus($order)->fresh([
                'driver',
                'pickupAddress',
                'deliveryAddress',
                'branch',
                'items.piece',
                'items.service',
                'items.additionalServicesPivot.serviceAddition',
            ]);

            // Laundry review pending client approval — return review summary for the app card.
            if ($order->status === OrderStatus::BRANCH_REVIEW->value) {
                $categorized = $this->categorizePendingApprovalItems($order, $lang);
                $title = $lang === 'ar' ? 'تم تحديث طلبك' : 'Your order has been updated';
                $message = $lang === 'ar'
                    ? 'قامت المغسلة بمراجعة الطلب، وكانت النتيجة كالتالي:'
                    : 'The laundry has reviewed the order. Here is the result:';
                $cardPayload = $this->getOnTheWayCardPayload($order, $lang);

                return [
                    'id' => $order->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'status_label' => $order->status_label,
                    'title' => $title,
                    'message' => $message,
                    ...$cardPayload,
                    'requires_handoff_confirmation' => false,
                    'requires_visit_response' => false,
                    'requires_vendor_action' => false,
                    'requires_review_approval' => true,
                    'required_action' => 'approve_or_reject',
                    'waiting_for' => 'client',
                    'vendor_pending_action' => null,
                    'time_remaining' => null,
                    'distance' => null,
                    'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                    'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                    'available_actions' => [
                        [
                            'action' => 'approve',
                            'label' => $lang === 'ar' ? 'قبول' : 'Accept',
                            'endpoint' => '/api/v1/user/orders/'.$order->id.'/receipt-status',
                            'method' => 'PUT',
                            'payload' => ['status' => 'approved'],
                        ],
                        [
                            'action' => 'reject',
                            'label' => $lang === 'ar' ? 'رفض' : 'Reject',
                            'endpoint' => '/api/v1/user/orders/'.$order->id.'/receipt-status',
                            'method' => 'PUT',
                            'payload' => ['status' => 'rejected'],
                        ],
                    ],
                    'handoff' => null,
                    'visit' => null,
                    'vendor_handoff' => null,
                    'accepted_items' => $categorized['accepted'],
                    'rejected_items' => $categorized['rejected'],
                    'modified_items' => $categorized['modified'],
                    'items_summary' => [
                        'total_items' => $order->items->count(),
                        'accepted_count' => count($categorized['accepted']),
                        'rejected_count' => count($categorized['rejected']),
                        'modified_count' => count($categorized['modified']),
                    ],
                    'pricing' => [
                        'original_total_amount' => (float) $order->original_total_amount,
                        'original_final_amount' => (float) $order->original_final_amount,
                        'new_total_amount' => (float) $order->total_amount,
                        'new_discount_amount' => (float) $order->discount_amount,
                        'new_tax_amount' => (float) $order->tax_amount,
                        'new_delivery_fee' => (float) $order->delivery_fee,
                        'new_final_amount' => (float) $order->final_amount,
                        'price_difference' => (float) ($order->final_amount - $order->original_final_amount),
                    ],
                    'pickup_time' => $order->pickup_time?->toIso8601String(),
                    'estimated_delivery_time' => $order->estimated_delivery_time?->toIso8601String(),
                    'driver' => null,
                ];
            }

            $isVendorSelfService = $this->isVendorSelfServiceOrder($order);
            $isBranchHandoffOrder = (bool) $order->pickup_at_vendor || (bool) $order->delivery_at_vendor;

            // Calculate time remaining until arrival
            $timeRemainingText = null;
            $estimatedTimeMinutes = null;
            $distanceKm = null;

            if (! $isVendorSelfService && $order->driver && $order->driver->latitude && $order->driver->longitude) {
                // Determine target address based on order status
                $targetAddress = null;
                if ($order->status === OrderStatus::ON_WAY_TO_PICKUP->value && $order->pickupAddress) {
                    $targetAddress = $order->pickupAddress;
                } elseif (in_array($order->status, [OrderStatus::PICKED_UP->value, OrderStatus::DELIVERED_TO_BRANCH->value]) && $order->branch) {
                    $targetAddress = $order->branch;
                } elseif (in_array($order->status, [OrderStatus::ON_WAY_TO_DELIVERY->value, OrderStatus::WAITING_CLIENT_RECEIPT->value, OrderStatus::DELIVERED->value]) && $order->deliveryAddress) {
                    $targetAddress = $order->deliveryAddress;
                }

                // Calculate distance and time if target address exists
                if ($targetAddress && $targetAddress->latitude && $targetAddress->longitude) {
                    $distanceKm = $this->calculateDistance(
                        (float) $order->driver->latitude,
                        (float) $order->driver->longitude,
                        (float) $targetAddress->latitude,
                        (float) $targetAddress->longitude
                    );

                    // Calculate estimated time (assuming average speed of 40 km/h in city)
                    $averageSpeedKmh = 40;
                    $estimatedTimeMinutes = ($distanceKm / $averageSpeedKmh) * 60;
                    $estimatedTimeMinutes = (int) round($estimatedTimeMinutes);

                    $timeRemainingText = $this->formatOnTheWayTimeRemaining($estimatedTimeMinutes, $lang);
                }
            }

            $handoffContext = $handoffService->resolveHandoffContext($order);
            $handoffActions = $handoffService->availableActions($order);
            $requiresHandoff = $handoffContext !== null;
            $visitApi = $visitService->apiResponseFields($order);
            $visitContext = $visitService->resolveVisitContext($order);
            $requiresVisitResponse = $visitApi['requires_visit_response'];
            $visitActions = $visitApi['available_actions'];
            $vendorHandoff = $vendorHandoffService->clientOnTheWayHandoff($order);
            $vendorPendingAction = $vendorHandoff['vendor_pending_action'] ?? null;
            // Vendor-side steps are informational on the client app — never a client action.
            $requiresVendorAction = false;
            $waitingForVendor = ! $requiresHandoff
                && ! $requiresVisitResponse
                && $vendorPendingAction === 'confirm_pickup_received'
                && $order->client_pickup_handoff_at;

            if ($requiresHandoff) {
                [$title, $message] = $this->getHandoffNotificationTexts($handoffContext, $lang);
            } elseif ($requiresVisitResponse) {
                [$title, $message] = $this->getVisitNotificationTexts($visitContext, $lang);
            } elseif (
                $vendorPendingAction === 'request_client_delivery'
                || ($vendorPendingAction === 'confirm_pickup_received' && $waitingForVendor)
                || $handoffService->isPendingBranchPickupReceipt($order)
                || ($isBranchHandoffOrder && $order->status === OrderStatus::DELIVERED_TO_BRANCH->value)
            ) {
                $title = $vendorHandoff['title'];
                $message = $vendorHandoff['message'];
            } elseif ($this->shouldUseDriverTrackingTexts($order)) {
                [$title, $message] = $this->getDriverTrackingNotificationTexts($order, $lang, $distanceKm);
            } elseif ($isBranchHandoffOrder) {
                $title = $vendorHandoff['title'];
                $message = $vendorHandoff['message'];
            } elseif ($isVendorSelfService) {
                [$title, $message] = $this->getVendorSelfServiceNotificationTexts($order->status, $lang);
            } else {
                [$title, $message] = $this->getOnTheWayNotificationTexts(
                    $order->status,
                    $lang,
                    $distanceKm
                );
            }

            if (
                in_array($order->status, [
                    OrderStatus::ON_WAY_TO_DELIVERY->value,
                    OrderStatus::WAITING_CLIENT_RECEIPT->value,
                ], true)
                && $estimatedTimeMinutes !== null
                && $estimatedTimeMinutes <= 10
            ) {
                $title = $this->transOrder('on_the_way_driver_approaching_title', $lang);
                $message = $this->transOrder('on_the_way_driver_approaching_message', $lang);
            }

            $clientActions = $requiresHandoff
                ? $handoffActions
                : ($requiresVisitResponse ? $visitActions : []);

            $vendorHandoff = array_merge($vendorHandoff, [
                'title' => $title,
                'message' => $message,
                'requires_vendor_action' => false,
                'waiting_for' => $waitingForVendor ? 'vendor' : null,
            ]);
            $cardPayload = $this->getOnTheWayCardPayload($order, $lang);

            $response = [
                'id' => $order->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_label' => $order->status_label,
                'title' => $title,
                'message' => $message,
                ...$cardPayload,
                'requires_handoff_confirmation' => $requiresHandoff,
                'requires_visit_response' => $requiresVisitResponse,
                'requires_vendor_action' => $requiresVendorAction,
                'waiting_for' => ($requiresHandoff || $requiresVisitResponse)
                    ? 'client'
                    : ($waitingForVendor ? 'vendor' : null),
                'vendor_pending_action' => $vendorPendingAction,
                'time_remaining' => $timeRemainingText,
                'distance' => $distanceKm !== null ? round($distanceKm, 1) : null,
                'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                'available_actions' => $clientActions,
                'handoff' => $handoffContext ? [
                    'type' => $handoffContext['handoff_type'],
                    'direction' => $handoffContext['direction'],
                    'confirm_label' => $handoffContext['confirm_label'],
                    'endpoint' => '/api/v1/user/orders/'.$order->id.'/confirm-handoff',
                    'confirm_action' => 'confirm',
                ] : null,
                'visit' => $visitApi['visit'],
                'vendor_handoff' => $vendorHandoff,
                ...$order->handoffResponseFields(),
                'pickup_time' => $order->pickup_time?->toIso8601String(),
                'estimated_delivery_time' => $order->estimated_delivery_time?->toIso8601String(),
                'driver' => $order->driver ? [
                    'id' => $order->driver->id,
                    'name' => $order->driver->getTranslation('full_name', $lang),
                    'phone' => $order->driver->phone,
                    'latitude' => $order->driver->latitude ? (float) $order->driver->latitude : null,
                    'longitude' => $order->driver->longitude ? (float) $order->driver->longitude : null,
                    'image' => $order->driver->image,
                ] : null,
            ];

            if (($isVendorSelfService || $isBranchHandoffOrder) && $order->branch) {
                $response['branch'] = $order->branch->toApiOrderBranchFlat($lang, [
                    'phone' => $order->branch->phone_number,
                ]);
            }

            return $response;
        });

        return successResponse([
            'orders' => $ordersData,
            'total' => $ordersData->count(),
        ], __('order.orders_on_the_way_retrieved'));
    }

    /**
     * Client confirms actual handoff: gave or received clothes (driver or laundry).
     *
     * POST /api/v1/user/orders/{order_id}/confirm-handoff
     */
    public function confirmHandoff(Request $request, int $order_id): JsonResponse
    {
        $user = $request->user();

        $order = Order::where('client_id', $user->id)->where('id', $order_id)->first();
        if (! $order) {
            return errorResponse(__('order.order_not_found'), 404);
        }

        $handoffService = app(\App\Services\ClientOrderHandoffService::class);
        $visitService = app(\App\Services\ClientOrderVisitService::class);

        if (! $handoffService->canConfirmHandoff($order)) {
            return errorResponse(__('order.handoff_not_available'), 400);
        }

        $context = $handoffService->resolveHandoffContext($order);

        try {
            $order = $handoffService->confirmHandoff($order, (int) $user->id);
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse($e->userMessage(), 400);
        } catch (\LogicException) {
            return errorResponse(__('order.handoff_not_available'), 400);
        }

        $message = match ($context['handoff_type'] ?? null) {
            'give_to_driver' => __('order.handoff_success_give_to_driver'),
            'give_to_laundry' => __('order.handoff_success_give_to_laundry'),
            'receive_from_driver' => __('order.handoff_success_receive_from_driver'),
            'receive_from_laundry' => __('order.handoff_success_receive_from_laundry'),
            default => __('order.handoff_success'),
        };

        $handoffContext = $handoffService->resolveHandoffContext($order);
        $handoffActions = $handoffService->availableActions($order);
        $visitApi = $visitService->apiResponseFields($order);

        return successResponse(array_merge([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'requires_handoff_confirmation' => $handoffContext !== null,
            'requires_visit_response' => $visitApi['requires_visit_response'],
            'available_actions' => $handoffContext !== null ? $handoffActions : $visitApi['available_actions'],
            'visit' => $visitApi['visit'],
            'handoff' => $handoffContext ? [
                'type' => $handoffContext['handoff_type'],
                'direction' => $handoffContext['direction'],
                'confirm_label' => $handoffContext['confirm_label'],
                'endpoint' => '/api/v1/user/orders/'.$order->id.'/confirm-handoff',
                'confirm_action' => 'confirm',
            ] : null,
        ], $order->clientVisitResponseFields()), $message);
    }

    /**
     * Client confirms readiness or postpones pickup/delivery while driver is assigned or en route.
     * Confirm does not change order status (notification only). Postpone updates status to client_postponed_*.
     *
     * POST body: action=confirm|postpone, reason (required if postpone), rescheduled_at (required if postpone)
     */
    public function respondToDriverVisit(Request $request, int $order_id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => ['required', 'string', 'in:confirm,postpone'],
            'reason' => ['required_if:action,postpone', 'nullable', 'string', 'max:500'],
            'rescheduled_at' => ['required_if:action,postpone', 'nullable', 'date', 'after:now'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        $lang = app()->getLocale();
        $action = strtolower((string) $request->input('action'));

        $order = Order::where('client_id', $user->id)->where('id', $order_id)->first();
        if (! $order) {
            return errorResponse(__('order.order_not_found'), 404);
        }

        $visitService = app(\App\Services\ClientOrderVisitService::class);

        if (! $visitService->canRespond($order)) {
            return errorResponse(__('order.visit_response_not_available'), 400);
        }

        $visitContext = $visitService->resolveVisitContext($order);

        try {
            if ($action === 'confirm') {
                $visitType = $visitContext['visit_type'] ?? null;
                $order = $visitService->confirmVisit($order, (int) $user->id);
                $message = match ($visitType) {
                    'receipt' => __('order.visit_confirm_success_receipt'),
                    'branch_dropoff' => __('order.visit_confirm_success_branch_dropoff'),
                    'branch_pickup' => __('order.visit_confirm_success_branch_pickup'),
                    'delivery' => __('order.visit_confirm_success_delivery'),
                    default => __('order.visit_confirm_success_pickup'),
                };
            } else {
                $reason = trim((string) $request->input('reason', ''));
                if ($reason === '') {
                    return errorResponse(__('order.visit_postpone_reason_required'), 422);
                }

                $rescheduledAt = $request->input('rescheduled_at');
                if (! $rescheduledAt) {
                    return errorResponse(__('order.visit_rescheduled_at_required'), 422);
                }

                $order = $visitService->postponeVisit(
                    $order,
                    (int) $user->id,
                    $reason,
                    \Illuminate\Support\Carbon::parse($rescheduledAt)
                );

                $message = $order->status === OrderStatus::CLIENT_POSTPONED_DELIVERY->value
                    ? __('order.visit_postpone_success_delivery')
                    : __('order.visit_postpone_success_pickup');
            }
        } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
            return errorResponse($e->userMessage(), 400);
        } catch (\LogicException) {
            return errorResponse(__('order.visit_response_not_available'), 400);
        }

        $visitApi = $visitService->apiResponseFields($order);

        return successResponse(array_merge([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'pickup_time' => $order->pickup_time?->toIso8601String(),
            'estimated_delivery_time' => $order->estimated_delivery_time?->toIso8601String(),
            'requires_visit_response' => $visitApi['requires_visit_response'],
            'available_actions' => $visitApi['available_actions'],
            'visit' => $visitApi['visit'],
        ], $order->clientVisitResponseFields()), $message);
    }

    /**
     * Update order status (including modifications approval and receipt statuses)
     */
    public function updateReceiptStatus(Request $request, int $order_id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:receipt_accepted,receipt_rejected'],
            'reason' => ['nullable', 'string', 'max:500'], // For rejection
            // Optional: how to settle a surcharge when the vendor review RAISED the
            // total on an already-paid order (string "visa" or array of methods).
            'payment_methods' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $methods = $this->orderPaymentService->normalizePaymentMethodsInput($value);
                    if ($methods === null) {
                        $fail(__('validation.required', ['attribute' => $attribute]));

                        return;
                    }
                    if (! $this->orderPaymentService->paymentMethodsAreAllowed($methods, $this->orderPaymentService->allowedSurchargeMethods())) {
                        $fail(__('validation.in', ['attribute' => $attribute]));
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        $lang = app()->getLocale();

        $order = Order::where('client_id', $user->id)
            ->where('id', $order_id)
            ->first();

        if (! $order) {
            return errorResponse(__('order.order_not_found'), 404);
        }

        $status = strtolower(trim($request->status ?? ''));

        // Map to internal values: receipt_accepted → approved, receipt_rejected → rejected
        if ($status === 'receipt_accepted') {
            $status = 'approved';
        } elseif ($status === 'receipt_rejected') {
            $status = 'rejected';
        }

        // Handle Modification Approval/Rejection (order at branch_review)
        if ($order->status === OrderStatus::BRANCH_REVIEW->value && in_array($status, ['approved', 'rejected'])) {
            $reviewService = app(\App\Services\VendorOrderReviewService::class);

            if ($status === 'approved') {
                $paymentMethods = $this->orderPaymentService->normalizePaymentMethodsInput($request->input('payment_methods'));

                $result = $reviewService->clientApproveModifications($order, $paymentMethods);

                // Vendor review RAISED the total on a paid order and it isn't settled
                // yet: surface the "pay the difference" step instead of confirming.
                if (! ($result['success'] ?? false) && ($result['payment_required'] ?? false)) {
                    return jsonResponse(false, 422, $result['message'] ?? __('order.update_requires_surcharge'), [
                        'payment_required' => true,
                        'amount_due' => (float) ($result['amount_due'] ?? 0),
                        'available_methods' => $result['available_methods'] ?? $this->orderPaymentService->allowedSurchargeMethods(),
                        'wallet_balance' => $result['wallet_balance'] ?? null,
                    ]);
                }

                if (! ($result['success'] ?? false)) {
                    return errorResponse($result['message'], 400);
                }

                $order->refresh();

                // Gateway surcharge issued: approval is deferred until payment completes.
                if ($result['awaiting_payment'] ?? false) {
                    return successResponse([
                        'order' => $result['order'],
                        'reconciliation' => $result['reconciliation'] ?? null,
                        'payment' => $result['payment'],
                        'awaiting_payment' => true,
                    ], $result['message'] ?? __('order.modifications_awaiting_payment'));
                }

                $successMessage = $order->isCashOnDelivery()
                    ? ($lang === 'ar'
                        ? 'تم قبول التعديلات بنجاح. سيتم متابعة الطلب والدفع عند إكمال التسليم'
                        : 'Modifications approved successfully. Your order will proceed; payment is due on delivery completion')
                    : ($order->isPaid()
                        ? ($lang === 'ar'
                            ? 'تم قبول التعديلات بنجاح. الدفع مكتمل وسيتم متابعة الطلب'
                            : 'Modifications approved successfully. Payment is complete and your order will proceed')
                        : ($lang === 'ar'
                            ? 'تم قبول التعديلات بنجاح. يرجى المتابعة للدفع'
                            : 'Modifications approved successfully. Please proceed to payment'));

                // Surcharge collected (or link issued): return order + payment so the app
                // can redirect a card payment. Refund / no-change: order only (as before).
                if (($result['payment'] ?? null) !== null) {
                    return successResponse([
                        'order' => $result['order'],
                        'reconciliation' => $result['reconciliation'] ?? null,
                        'payment' => $result['payment'],
                        'awaiting_payment' => false,
                    ], $successMessage);
                }

                return successResponse($result['order'], $successMessage);
            } else {
                $result = $reviewService->clientRejectModifications($order, $request->reason);
                $successMessage = $lang === 'ar'
                    ? 'تم رفض التعديلات وإلغاء الطلب'
                    : 'Modifications rejected and order cancelled';

                if ($result['success']) {
                    return successResponse($result['order'], $successMessage);
                }

                return errorResponse($result['message'], 400);
            }
        }

        // Handle delivery confirmation (order at delivered status)
        if ($order->status === OrderStatus::DELIVERED->value && $status === 'approved') {
            $statusService = app(\App\Services\OrderStatusService::class);
            try {
                $statusService->transitionTo($order, OrderStatus::COMPLETED, [
                    'notes' => 'Receipt accepted by client',
                    'changed_by' => $user->id,
                    'actor_type' => 'client',
                    'actor_id' => $user->id,
                ]);
                $isCashOnDelivery = ($order->payment_method ?? null) === PaymentMethod::CASH_ON_DELIVERY->value;
                if ($isCashOnDelivery && ! $order->isPaid()) {
                    $this->markOrderPaymentPaid($order);
                }
            } catch (\App\Exceptions\InvalidStatusTransitionException $e) {
                return errorResponse($e->userMessage(), 400);
            }

            $freshOrder = $order->fresh();

            $responseData = array_merge([
                'order_id' => $freshOrder->id,
                'order_number' => $freshOrder->order_number,
                'status' => $freshOrder->status,
                'status_label' => OrderStatus::fromString($freshOrder->status)?->localizedLabel($order->payment_method, $freshOrder->status === OrderStatus::COMPLETED->value && ! $freshOrder->client_delivery_handoff_at, (bool) $order->delivery_at_vendor) ?? $freshOrder->status,
            ], $freshOrder->clientVisitResponseFields());

            if ($freshOrder->status === OrderStatus::COMPLETED->value) {
                $responseData['invoice'] = $this->completedOrderInvoiceSummary($freshOrder);
            }

            return successResponse($responseData, __('order.receipt_accepted'));
        }

        return errorResponse(__('order.invalid_status'), 400);
    }

    /**
     * Build the ZATCA tax invoice summary attached to responses once an order is completed.
     */
    private function completedOrderInvoiceSummary(Order $order): ?array
    {
        try {
            return app(\Modules\Invoice\Services\InvoiceService::class)->invoiceSummaryForOrder($order);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to build invoice summary for completed order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Mark order payment as paid (create or update PaymentTransaction to completed).
     */
    private function markOrderPaymentPaid(Order $order): void
    {
        $tx = $order->paymentTransactions()->latest()->first();
        if ($tx) {
            $tx->update([
                'status' => 'completed',
                'paid_at' => $tx->paid_at ?? now(),
            ]);
        } else {
            $order->paymentTransactions()->create([
                'gateway' => 'cash_on_delivery',
                'transaction_id' => 'COD-'.$order->id.'-'.time(),
                'amount' => $order->final_amount,
                'currency' => 'SAR',
                'status' => 'completed',
                'payment_method' => $order->payment_method ?? 'cash_on_delivery',
                'paid_at' => now(),
            ]);
        }
    }
}
