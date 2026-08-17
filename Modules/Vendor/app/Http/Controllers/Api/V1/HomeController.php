<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Vendor\Models\Vendor;

class HomeController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Format service additions for a vendor order item.
     */
    private function formatOrderItemServiceAdditions($item, Order $order): array
    {
        if (! $item->relationLoaded('additionalServicesPivot')) {
            return [];
        }

        $locale = app()->getLocale();

        return $item->additionalServicesPivot->map(function ($pivot) use ($order, $locale) {
            $addition = $pivot->serviceAddition;
            if (! $addition) {
                return null;
            }

            $quantity = (int) ($pivot->quantity ?? 1);
            $price = \App\Support\OrderItemDisplayNames::storedAdditionalServiceUnitPrice($pivot);

            return array_merge([
                'id' => $addition->id,
                'name' => \App\Support\OrderItemDisplayNames::additionalServiceName($addition, (int) $order->branch_id, $locale),
                'price' => $price,
                'quantity' => $quantity,
                'total_price' => $price * $quantity,
                'icon' => \App\Support\OrderItemDisplayNames::additionalServiceIconUrl($addition, (int) $order->branch_id),
                'status' => $pivot->vendor_status ?? 'pending',
            ], \App\Support\CatalogActivePresenter::serviceAddition($addition, (int) $order->branch_id));
        })->filter()->values()->toArray();
    }

    /**
     * Get vendor user data (full data)
     */
    public function getUserData(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendor = $employee->vendor;

        if (! $vendor) {
            return notFoundResponse(__('vendor.vendor_not_found'));
        }

        // Load relationships
        $vendor->load(['branches']);

        // Format attachments
        $attachments = [];
        if ($vendor->attachments && is_array($vendor->attachments)) {
            $attachments = collect($vendor->attachments)->map(function ($attachment, $index) {
                return [
                    'id' => $index + 1,
                    'type' => $attachment['type'] ?? $this->getFileType($attachment['url'] ?? ''),
                    'url' => $this->uploadFilesService->getFullUrl($attachment['url'] ?? $attachment),
                ];
            })->values()->toArray();
        }

        return successResponse([
            'id' => $vendor->id,
            'name' => [
                'ar' => $vendor->getTranslation('name', 'ar'),
                'en' => $vendor->getTranslation('name', 'en'),
            ],
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
            'official_number' => $vendor->official_number,
            'vat_number' => $vendor->vat_number,
            'delivery_price_per_km' => (float) ($vendor->delivery_price_per_km ?? 0),
            'wallet_balance' => (float) ($vendor->wallet_balance ?? 0),
            'is_active' => (bool) $vendor->is_active,
            'is_verified' => (bool) $vendor->is_verified,
            'is_banned' => (bool) ($vendor->is_banned ?? false),
            'ban_reason' => $vendor->ban_reason,
            'banned_at' => $vendor->banned_at?->toDateTimeString(),
            'attachments' => $attachments,
            'branches_count' => $vendor->branches->count(),
            'created_at' => $vendor->created_at?->toDateTimeString(),
            'updated_at' => $vendor->updated_at?->toDateTimeString(),
        ], __('vendor.user_data_retrieved'));
    }

    /**
     * Get file type from URL
     */
    private function getFileType(string $url): string
    {
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        } elseif (in_array($extension, $documentExtensions)) {
            return $extension;
        }

        return 'file';
    }

    /**
     * Get home dashboard data (internal)
     */
    public function getHomeDashboard(Request $request)
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Get branch IDs for this vendor
        $branchIdsQuery = Branch::where('vendor_id', $vendorId);
        if ($request->has('branch_id')) {
            $branchIdsQuery->where('id', $request->branch_id);
        }
        $branchIds = $branchIdsQuery->pluck('id');

        // KPI Metrics - new orders = pending + branch_review without driver assigned
        $newOrders = Order::whereIn('branch_id', $branchIds)
            ->whereIn('status', OrderStatus::newOrderStatuses())
            ->whereNull('driver_id')
            ->count();

        $totalOrders = Order::whereIn('branch_id', $branchIds)->count();

        $activeOrders = Order::whereIn('branch_id', $branchIds)
            ->whereIn('status', [
                OrderStatus::CONFIRMED->value,
                OrderStatus::PICKED_UP->value,
                OrderStatus::DELIVERED_TO_BRANCH->value,
                OrderStatus::ON_WAY_TO_DELIVERY->value,
            ])
            ->count();

        $todayOrders = Order::whereIn('branch_id', $branchIds)
            ->whereDate('created_at', today())
            ->count();

        $monthlyRevenue = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('final_amount');

        // Revenue Chart (Last 6 months) - Based on branch orders
        $revenueChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $amount = Order::whereIn('branch_id', $branchIds)
                ->where('status', OrderStatus::COMPLETED->value)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->sum('final_amount');

            $revenueChart[] = [
                'month' => $month->format('M'),
                'amount' => (float) $amount,
            ];
        }

        // Services (only admin-active services related to vendor's branches)
        $services = Service::where('services.is_active', true)
            ->whereHas('branches', function ($query) use ($branchIds) {
                $query->whereIn('branches.id', $branchIds);
            })
            ->with(['category'])
            ->get()
            ->map(function ($service) use ($vendorId) {
                return array_merge([
                    'service_id' => $service->id,
                    'name' => $service->service_name,
                    'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                ], \App\Support\CatalogActivePresenter::service($service, null, null, (int) $vendorId));
            });

        // Staff (Drivers) - Get available drivers assigned to vendor's branches
        $staff = Driver::whereIn('branch_id', $branchIds)
            ->limit(10)
            ->get()
            ->map(function ($driver) {
                return [
                    'driver_id' => $driver->id,
                    'name' => $driver->full_name,
                    'contact_info' => $driver->phone,
                    'image' => $this->uploadFilesService->getFullUrl($driver->image),
                ];
            });

        // Reviews (Last 5 reviews)
        $reviews = Order::whereIn('branch_id', $branchIds)
            ->whereNotNull('rating')
            ->with(['client', 'branch'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($order) {
                $clientName = 'Anonymous';
                if ($order->client) {
                    $clientName = $order->client->full_name ?? 'Anonymous';
                }

                return [
                    'user_name' => $clientName,
                    'rating' => $order->rating,
                    'comment' => $order->review,
                    'image' => $order->client ? $this->uploadFilesService->getFullUrl($order->client->image) : null,
                    'date' => $order->created_at->format('Y-m-d'),
                    'branch_id' => $order->branch_id,
                    'branch_name' => $order->branch ? $order->branch->getTranslation('name', app()->getLocale()) : null,
                ];
            });

        return successResponse([
            'kpi_metrics' => [
                'new_orders' => $newOrders,
                'total_orders' => $totalOrders,
                'active_orders' => $activeOrders,
                'today_orders' => $todayOrders,
                'Monthly_revenue' => (float) $monthlyRevenue,
            ],
            'revenue_chart' => $revenueChart,
            'services' => $services,
            'staff' => $staff,
            'reviews' => $reviews,
        ], __('vendor.dashboard_data_retrieved'));
    }

    /**
     * Get services list
     */
    public function getServices(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $request->input('vendor_id') ?? $request->input('vendot_id') ?? $employee->vendor_id;
        $systemService = $request->boolean('system_service');

        // If system_service=true, return all system services with global ratings
        if ($systemService) {
            $services = Service::with(['category'])
                ->where('services.is_active', true)
                ->get()
                ->map(function ($service) {
                    // Global rating from all orders
                    $rating = Order::whereHas('items.service', function ($query) use ($service) {
                        $query->where('services.id', $service->id);
                    })
                        ->whereNotNull('rating')
                        ->avg('rating') ?? 0;

                    return array_merge([
                        'service_id' => $service->id,
                        'title' => $service->getTranslation('service_name', app()->getLocale()),
                        'price' => (float) ($service->price ?? 0),
                        'rating' => round((float) $rating, 2),
                        'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                    ], \App\Support\CatalogActivePresenter::service($service));
                });

            return successResponse($services, __('vendor.services_retrieved_successfully'));
        }

        // Get branch IDs for this vendor
        $branchIdsQuery = Branch::where('vendor_id', $vendorId);
        if ($request->has('branch_id')) {
            $branchIdsQuery->where('id', $request->branch_id);
        }
        $branchIds = $branchIdsQuery->pluck('id');

        // Get services related to vendor's branches
        $services = Service::where('services.is_active', true)
            ->whereHas('branches', function ($query) use ($branchIds) {
                $query->whereIn('branches.id', $branchIds);
            })
            ->with(['category'])
            ->get()
            ->map(function ($service) use ($branchIds, $vendorId) {
                // Calculate rating only for orders from this vendor's branches
                $rating = Order::whereHas('items.service', function ($query) use ($service) {
                    $query->where('services.id', $service->id);
                })
                    ->whereIn('branch_id', $branchIds)
                    ->whereNotNull('rating')
                    ->avg('rating') ?? 0;

                return array_merge([
                    'service_id' => $service->id,
                    'title' => $service->getTranslation('service_name', app()->getLocale()),
                    'price' => (float) ($service->price ?? 0),
                    'rating' => round((float) $rating, 2),
                    'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                ], \App\Support\CatalogActivePresenter::service($service, null, null, (int) $vendorId));
            });

        return successResponse($services, __('vendor.services_retrieved_successfully'));
    }

    /**
     * Get new orders
     */
    public function getNewOrders(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Get branch IDs for this vendor
        $branchIdsQuery = Branch::where('vendor_id', $vendorId);
        if ($request->has('branch_id')) {
            $branchIdsQuery->where('id', $request->branch_id);
        }
        $branchIds = $branchIdsQuery->pluck('id');

        // Build query
        $query = Order::whereIn('branch_id', $branchIds);

        // Filter by status if provided, otherwise default to pending orders
        if ($request->has('status')) {
            $status = $request->status;
            if (is_array($status)) {
                $query->whereIn('status', $status);
            } else {
                $query->where('status', $status);
            }
        } else {
            $query->whereIn('status', OrderStatus::newOrderStatuses());
        }

        $orders = $query->with([
            'client',
            'driver',
            'items.piece',
            'items.service',
            'items.additionalServicesPivot.serviceAddition',
            'pickupAddress',
            'branch',
        ])
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($order) {
                $locale = app()->getLocale();
                $firstItem = $order->items->first();

                $branchId = (int) ($order->branch_id ?? 0);
                $pieceName = 'Order';
                if ($firstItem && $firstItem->piece) {
                    $pieceName = \App\Support\OrderItemDisplayNames::pieceName($firstItem->piece, $branchId, $locale) ?: 'Order';
                }

                $driverName = null;
                if ($order->driver && ! OrderStatus::isNewOrderStatus($order->status)) {
                    $driverName = $order->driver->getTranslation('full_name', $locale);
                }

                // First item image (from order items)
                $firstItemImage = null;
                $itemWithImage = $order->items->first(fn ($item) => ! empty($item->images));
                if ($itemWithImage) {
                    $firstItemImage = $this->uploadFilesService->getFullUrl($itemWithImage->images);
                }

                $branchLocation = $order->branch?->getApiLocation($locale);

                $items = collect(\Modules\Order\Support\OrderItemGrouper::toApiLines(
                    $order->items,
                    $branchId,
                    $locale,
                    fn ($item) => $item->images ? $this->uploadFilesService->getFullUrl($item->images) : null
                ))->map(function (array $g) use ($order, $branchId, $locale) {
                    $primaryItem = $order->items->firstWhere('id', $g['id']);

                    // Collect service additions from all grouped items
                    $serviceAdditions = [];
                    foreach ($g['ids'] ?? [$g['id']] as $itemId) {
                        $itemModel = $order->items->firstWhere('id', $itemId);
                        if ($itemModel) {
                            $serviceAdditions = array_merge($serviceAdditions, $this->formatOrderItemServiceAdditions($itemModel, $order));
                        }
                    }

                    // Build services with CatalogActivePresenter
                    $servicesData = [];
                    foreach ($g['services'] ?? [] as $svc) {
                        $svcEntry = [
                            'id' => $svc['id'],
                            'name' => $svc['name'] ?? '',
                        ];
                        $serviceModel = $primaryItem?->piece?->services->firstWhere('id', $svc['id']);
                        if ($serviceModel) {
                            $svcEntry = array_merge($svcEntry, \App\Support\CatalogActivePresenter::service($serviceModel, $branchId));
                        }
                        $servicesData[] = $svcEntry;
                    }

                    return [
                        'item_id' => $g['id'],
                        'piece_id' => $primaryItem->piece_id ?? null,
                        'item_name' => $g['piece']['name'] ?? 'Item',
                        'quantity' => $g['quantity'],
                        'unit_price' => (float) $g['unit_price'],
                        'total_price' => (float) $g['total_price'],
                        'status' => $g['status'] ?? 'pending',
                        'service' => $servicesData[0] ?? null,
                        'services' => $servicesData,
                        'service_additions' => $serviceAdditions,
                    ];
                })->values();

                $result = array_merge([
                    'Title' => $pieceName,
                    'sub_title' => $order->order_number,
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'status_label' => OrderStatus::fromString($order->status)?->localizedLabel($order->payment_method) ?? $order->status,
                    'Customer_name' => $order->client ? $order->client->full_name : 'Customer',
                    'distance' => $order->distance !== null ? (float) $order->distance : 0,
                    'first_item_image' => $firstItemImage,
                    'branch_location' => $branchLocation,
                    'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                    'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                    'branch_id' => $order->branch_id,
                    'rating' => $order->rating !== null ? (int) $order->rating : null,
                    'review' => $order->review,
                    'items' => $items,
                    'items_count' => \Modules\Order\Support\OrderItemGrouper::totalPiecesCount($order->items),
                ], $order->clientVisitResponseFields());

                // Only add delivery_name if driver is assigned
                if ($driverName) {
                    $result['delivery_name'] = $driverName;
                }

                return $result;
            });

        return successResponse($orders, __('vendor.new_orders_retrieved'));
    }

    public function getOrderDetails(Request $request, $orderId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Get branch IDs for this vendor
        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id');

        $order = Order::where('id', $orderId)
            ->whereIn('branch_id', $branchIds)
            ->with([
                'client',
                'driver',
                'branch.workingHourShifts',
                'latestPayment',
                'pickupDriver',
                'deliveryDriver',
                // Live items only — soft-deleted rows from client edits must not
                // appear or vendor review will send invalid item_ids.
                'items' => fn ($q) => $q->with([
                    'piece.iconRelation',
                    'service.iconRelation',
                    'additionalServicesPivot.serviceAddition.iconRelation',
                ]),
                'pickupAddress',
                'deliveryAddress',
                'statusLogs',
                'discount',
            ])
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $uploadService = $this->uploadFilesService;

        // Build items — group multi-service piece lines (same logic as user tracking)
        $locale = app()->getLocale();
        $branchId = (int) ($order->branch_id ?? 0);

        $items = collect(\Modules\Order\Support\OrderItemGrouper::toApiLines(
            $order->items,
            $branchId,
            $locale,
            fn ($item) => $item->images ? $uploadService->getFullUrl($item->images) : null
        ))->map(function (array $grouped) use ($order, $branchId, $locale) {
            $primaryItemId = $grouped['id'];
            $primaryItem = $order->items->firstWhere('id', $primaryItemId);

            $pieceName = $grouped['piece']['name'] ?? 'Item';

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

            // Collect service additions from all grouped items
            $serviceAdditions = [];
            $groupItemIds = $grouped['ids'] ?? [$primaryItemId];
            foreach ($groupItemIds as $itemId) {
                $itemModel = $order->items->firstWhere('id', $itemId);
                if ($itemModel) {
                    $serviceAdditions = array_merge($serviceAdditions, $this->formatOrderItemServiceAdditions($itemModel, $order));
                }
            }

            // Build services array with CatalogActivePresenter data
            $servicesData = [];
            foreach ($grouped['services'] ?? [] as $svc) {
                $serviceModel = $primaryItem ? $primaryItem->piece?->services->firstWhere('id', $svc['id']) : null;
                $svcEntry = [
                    'id' => $svc['id'],
                    'name' => $svc['name'] ?? $svc['service_name'] ?? '',
                    'price' => (float) ($svc['price'] ?? 0),
                    'icon' => $svc['icon'] ?? null,
                ];
                if ($serviceModel) {
                    $svcEntry = array_merge($svcEntry, \App\Support\CatalogActivePresenter::service($serviceModel, $branchId));
                }
                $servicesData[] = $svcEntry;
            }

            $primaryServiceData = $servicesData[0] ?? null;
            $servicesTotalPrice = (float) collect($servicesData)->sum('price');

            $pieceData = null;
            if ($primaryItem && $primaryItem->piece) {
                $pieceData = array_merge([
                    'id' => $primaryItem->piece->id,
                    'name' => $pieceName,
                    'icon' => \App\Support\OrderItemDisplayNames::pieceIconUrl($primaryItem->piece),
                ], \App\Support\CatalogActivePresenter::piece($primaryItem->piece, $branchId));
            }

            return [
                'item_id' => $primaryItemId,
                'item_ids' => $groupItemIds,
                'piece_id' => $primaryItem->piece_id ?? null,
                'item_name' => $pieceName,
                'service_price' => $servicesTotalPrice,
                'additional_services_total' => (float) ($grouped['additional_services_total'] ?? 0),
                'quantity' => (int) ($grouped['quantity'] ?? 1),
                'unit_price' => (float) ($grouped['unit_price'] ?? 0),
                'total_price' => (float) ($grouped['total_price'] ?? 0),
                'status' => $grouped['status'] ?? 'pending',
                'note' => $grouped['note'] ?? null,
                'image' => $grouped['image'] ?? null,
                'modifiers' => $modifiers,
                'service_additions' => $serviceAdditions,
                'service' => $primaryServiceData,
                'services' => $servicesData,
                'piece' => $pieceData,
            ];
        });

        $acceptedItems = $items->filter(fn ($i) => ($i['status'] ?? 'accepted') !== 'rejected')->values();
        $rejectedItems = $items->filter(fn ($i) => ($i['status'] ?? 'accepted') === 'rejected')->values();
        // Fully rejected pieces: fold additions into services so mobile shows them on one line.
        $rejectedItems = $rejectedItems->map(function (array $item) {
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
            $item['service_additions'] = [];

            return $item;
        })->values();
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

                return [
                    'item_id' => $item['item_id'],
                    'item_ids' => $item['item_ids'] ?? [$item['item_id']],
                    'piece_id' => $item['piece_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'service_price' => 0.0,
                    'additional_services_total' => $additionsTotal,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_price' => $additionsTotal,
                    'total_price' => $additionsTotal,
                    'status' => 'rejected',
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

        $toBreakdownLine = function (array $item): array {
            $serviceNames = collect($item['services'] ?? [])
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
            $additionNames = collect($item['service_additions'] ?? [])
                ->pluck('name')
                ->filter()
                ->values()
                ->all();

            return [
                'Item_name' => $item['item_name'],
                'name_operation' => $serviceNames !== []
                    ? implode('، ', $serviceNames)
                    : ($additionNames !== []
                        ? implode('، ', $additionNames)
                        : ($item['service']['name'] ?? 'Service')),
                'Quantity' => $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'total_price' => (float) $item['total_price'],
                'status' => $item['status'] ?? 'accepted',
                'service_additions' => $item['service_additions'] ?? [],
                'services' => $item['services'] ?? [],
            ];
        };

        // Use stored order totals so all order APIs return same amounts
        $priceBreakdown = [
            'accepted_items' => $acceptedItems->map($toBreakdownLine)->values(),
            'rejected_items' => $rejectedItems->map($toBreakdownLine)->values(),
            'subtotal' => (float) $order->total_amount,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount_amount,
            'tax' => (float) $order->tax_amount,
            'final_total' => (float) $order->final_amount,
        ];

        $clientInfo = $order->client
            ? $order->client->toApiClientInfo(
                app()->getLocale(),
                $uploadService->getFullUrl($order->client->image)
            )
            : null;

        // Driver info
        $driverInfo = null;
        if ($order->driver) {
            $driverInfo = [
                'id' => $order->driver->id,
                'name' => is_array($order->driver->full_name)
                    ? ($order->driver->full_name[app()->getLocale()] ?? $order->driver->full_name['en'] ?? 'Driver')
                    : $order->driver->full_name,
                'phone_number' => $order->driver->phone,
                'rating' => (float) ($order->driver->rating ?? 0),
                'image' => $uploadService->getFullUrl($order->driver->image),
            ];
        }

        // Location GPS: pickup address when pickup from home, else branch location
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

        // User review
        $userReview = null;
        if ($order->rating && $order->status === OrderStatus::COMPLETED->value) {
            $userReview = [
                'user_name' => $order->client?->full_name ?? 'Anonymous',
                'rating' => $order->rating,
                'comment' => $order->review,
                'date' => $order->updated_at?->format('Y-m-d'),
            ];
        }

        // Format pickup address
        $pickupAddressStr = null;
        if ($order->pickupAddress) {
            $addressParts = array_filter([
                $order->pickupAddress->street_name,
                $order->pickupAddress->building_number,
                $order->pickupAddress->district,
                $order->pickupAddress->city,
            ]);
            $pickupAddressStr = ! empty($addressParts)
                ? implode(', ', $addressParts)
                : ($order->pickupAddress->address_text ?? $order->pickupAddress->national_address);
        }

        // Format delivery address
        $deliveryAddressStr = null;
        if ($order->deliveryAddress) {
            $addressParts = array_filter([
                $order->deliveryAddress->street_name,
                $order->deliveryAddress->building_number,
                $order->deliveryAddress->district,
                $order->deliveryAddress->city,
            ]);
            $deliveryAddressStr = ! empty($addressParts)
                ? implode(', ', $addressParts)
                : ($order->deliveryAddress->address_text ?? $order->deliveryAddress->national_address);
        }

        $pickupAddressInfo = $order->pickupAddress ? [
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
        ] : null;

        $deliveryAddressInfo = $order->deliveryAddress ? [
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
        ] : null;

        $pickupAddressObject = $order->pickupAddress ? [
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
        ] : null;

        $deliveryAddressObject = $order->deliveryAddress ? [
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
        ] : null;

        // Branch with location
        $branchData = $order->branch?->toApiOrderBranch(app()->getLocale());

        $chatService = app(\Modules\Chat\Services\ChatService::class);
        // Ensure vendor chat exists for this order even before first message
        $conversation = $chatService->ensureConversationForOrder($order->id, (int) $order->client_id, $vendorId, null);
        $chat = [
            'conversation_id' => $conversation?->id,
            'order_id' => $conversation?->order_id ?? $order->id,
        ];

        return successResponse(array_merge([
            'id' => $order->id,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'order_number' => $order->order_number,
            'branch_id' => $order->branch_id,
            'total_price' => (float) $order->total_amount,
            'total_amount' => (float) $order->total_amount,
            'discount_amount' => (float) $order->discount_amount,
            ...$order->couponResponseFields(app()->getLocale()),
            'tax_amount' => (float) $order->tax_amount,
            'delivery_fee' => (float) $order->delivery_fee,
            'final_amount' => (float) $order->final_amount,
            'distance' => $order->distance !== null ? (float) $order->distance : 0,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status ?? 'pending',
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($order->payment_status ?? 'pending'),
            'payment_breakdown' => $order->paymentBreakdownForApi(),
            'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
            'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
            'driver_id' => $order->driver_id,
            'pickup_driver_id' => $order->pickup_driver_id,
            'delivery_driver_id' => $order->delivery_driver_id,
            'customer_name' => $order->client ? (is_array($order->client->full_name) ? ($order->client->full_name[app()->getLocale()] ?? $order->client->full_name['en'] ?? 'Unknown') : $order->client->full_name) : 'Unknown',
            'client_info' => $clientInfo,
            'branch' => $branchData,
            'pickup_address_id' => $order->pickup_address_id,
            'delivery_address_id' => $order->delivery_address_id,
            'pickup_address' => $pickupAddressObject,
            'delivery_address' => $deliveryAddressObject,
            'pickup_address_text' => $pickupAddressStr,
            'delivery_address_text' => $deliveryAddressStr,
            'Location_gps' => $locationGps,
            'accepted_items' => $acceptedItems->values()->toArray(),
            'rejected_items' => $rejectedItems->values()->toArray(),
            'driver_info' => $driverInfo,
            'driver_name' => $order->driver ? (is_array($order->driver->full_name) ? ($order->driver->full_name[app()->getLocale()] ?? $order->driver->full_name['en'] ?? null) : $order->driver->full_name) : null,
            'user_review' => $userReview,
            'rating' => $order->rating !== null ? (int) $order->rating : null,
            'review' => $order->review,
            'Price_breakdown' => $priceBreakdown,
            'vendor_chat' => $chat,
            'chat' => $chat,
        ], $order->clientVisitResponseFields()), __('order.order_details_retrieved'));
    }

    /**
     * Get available drivers for order
     */
    public function getDriversAvailable(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'order_id' => ['required', 'exists:orders,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id');

        $order = Order::with(['pickupAddress', 'deliveryAddress', 'branch'])
            ->whereIn('branch_id', $branchIds)
            ->find($request->order_id);

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        // When re-assigning, hide the driver already assigned on the current leg
        // so the vendor can pick a different one.
        $excludeDriverId = $this->resolveCurrentlyAssignedDriverIdForAvailableList($order);
        $assignmentLeg = $this->resolveDriversAvailableAssignmentLeg($order);
        $sortReference = $assignmentLeg
            ? $this->resolveDriversAvailableSortCoordinates($order, $assignmentLeg)
            : null;

        $drivers = Driver::where('branch_id', $order->branch_id)
            ->where('is_available', true)
            ->when($excludeDriverId, fn ($query) => $query->where('id', '!=', $excludeDriverId))
            ->get()
            ->map(function ($driver) use ($sortReference) {
                $lang = app()->getLocale();
                $distanceKm = null;

                if ($sortReference && $driver->latitude && $driver->longitude) {
                    $distanceKm = round($this->calculateDistance(
                        $sortReference['latitude'],
                        $sortReference['longitude'],
                        (float) $driver->latitude,
                        (float) $driver->longitude
                    ), 2);
                }

                return [
                    'driver_id' => $driver->id,
                    'name' => $driver->getTranslation('full_name', $lang),
                    'rating' => (float) ($driver->rating ?? 0),
                    'location_text' => $this->formatDriverLocationText($driver),
                    'image' => $this->uploadFilesService->getFullUrl($driver->image),
                    'branch_id' => $driver->branch_id,
                    'phone' => $driver->phone,
                    'full_name' => $driver->getTranslations('full_name'),
                    'email' => $driver->email,
                    'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                    'longitude' => $driver->longitude ? (float) $driver->longitude : null,
                    'distance_km' => $distanceKm,
                    'total_orders' => (int) $driver->total_orders,
                ];
            })
            ->sortBy(function ($driver) {
                return $driver['distance_km'] ?? PHP_FLOAT_MAX;
            })
            ->values();

        $address = null;
        if ($assignmentLeg === 'delivery' && $order->branch) {
            $branchLocation = $order->branch->location;
            $address = is_array($branchLocation)
                ? ($branchLocation[app()->getLocale()] ?? $branchLocation['ar'] ?? $branchLocation['en'] ?? null)
                : $branchLocation;
        } elseif ($order->pickupAddress) {
            $address = $order->pickupAddress->street_name ?: ($order->pickupAddress->building_number ?: $order->pickupAddress->national_address);
        } elseif ($order->deliveryAddress) {
            $address = $order->deliveryAddress->street_name ?: ($order->deliveryAddress->building_number ?: $order->deliveryAddress->national_address);
        }

        return successResponse([
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'assignment_leg' => $assignmentLeg,
            'sort_reference' => $sortReference['reference'] ?? null,
            'address' => $address,
            'drivers' => $drivers,
        ], __('vendor.available_drivers_retrieved'));
    }

    /**
     * Assign driver to order.
     * Vendor may assign/re-assign pickup or delivery drivers regardless of order status
     * (except cancelled orders and branch handoff legs).
     */
    public function assignDriver(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'order_id' => ['required', 'exists:orders,id'],
            'driver_id' => ['nullable', 'exists:drivers,id'],
            'pickup_driver_id' => ['nullable', 'exists:drivers,id'],
            'delivery_driver_id' => ['nullable', 'exists:drivers,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        if (! $request->pickup_driver_id && ! $request->delivery_driver_id && ! $request->driver_id) {
            return errorResponse(__('driver.at_least_one_driver_id_required'), null, 400);
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id');

        $order = Order::whereIn('branch_id', $branchIds)->find($request->order_id);

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        if ($order->status === OrderStatus::CANCELLED->value) {
            return errorResponse(__('driver.driver_assignment_failed'), [
                'order_status' => $order->status,
            ], 400);
        }

        // Order must need at least one driver (not both pickup and delivery at vendor)
        if (! $order->needsPickupDriver() && ! $order->needsDeliveryDriver()) {
            return errorResponse(__('driver.no_driver_needed_both_at_vendor'), null, 400);
        }

        $assignment = $this->resolveVendorDriverAssignment($order, $request);
        $assignmentType = $assignment['type'];
        $driverId = $assignment['driver_id'];

        if (! $assignmentType || ! $driverId) {
            $currentStatus = OrderStatus::tryFrom($order->status);

            return errorResponse(
                __('driver.no_driver_assignment_needed', [
                    'status' => $currentStatus?->localizedLabel($order->payment_method) ?? $order->status,
                ]),
                [
                    'order_status' => $order->status,
                    'order_status_label' => $currentStatus?->localizedLabel($order->payment_method) ?? $order->status,
                    'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
                    'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
                ],
                400
            );
        }

        $statusService = app(\App\Services\OrderStatusService::class);
        $driver = Driver::find($driverId);

        if (! $driver || ! $driver->is_available) {
            return errorResponse(__('driver.driver_not_available'), null, 400);
        }

        if ($driver->branch_id != $order->branch_id) {
            return errorResponse(__('driver.driver_branch_mismatch'), null, 400);
        }

        try {
            if ($assignmentType === 'pickup') {
                $statusService->assignPickupDriver($order, $driver, $employee->id);
            } else {
                $statusService->assignDeliveryDriver($order, $driver, $employee->id);
            }
        } catch (\Throwable $e) {
            return errorResponse($e->getMessage() ?: __('driver.driver_assignment_failed'), null, 400);
        }

        $driverName = is_array($driver->full_name) ? ($driver->full_name['en'] ?? $driver->full_name['ar'] ?? '') : $driver->full_name;

        return successResponse(array_merge([
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'driver_name' => $driverName,
            'assignment_type' => $assignmentType,
            'pickup_driver_id' => $order->fresh()->pickup_driver_id,
            'delivery_driver_id' => $order->fresh()->delivery_driver_id,
            'pickup_at_vendor' => (bool) $order->pickup_at_vendor,
            'delivery_at_vendor' => (bool) $order->delivery_at_vendor,
        ], $order->fresh()->clientVisitResponseFields()), __('driver.driver_assigned'));
    }

    /**
     * Get QR code for order. Generates a new QR code each time and saves it to the order.
     */
    public function getQRCode(Request $request, $orderId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id');

        $order = Order::where('id', $orderId)
            ->whereIn('branch_id', $branchIds)
            ->first();

        if (! $order) {
            return notFoundResponse(__('order.order_not_found'));
        }

        $newQrCode = $order->order_number.'-'.time().'-'.bin2hex(random_bytes(4));
        $order->update(['qr_code' => $newQrCode]);

        return successResponse([
            'order_id' => $order->id,
            'QR Code' => $newQrCode,
        ], __('vendor.qr_code_retrieved'));
    }

    /**
     * Get featured drivers
     */
    public function getFeaturedDrivers(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIdsQuery = Branch::where('vendor_id', $vendorId);
        if ($request->has('branch_id')) {
            $branchIdsQuery->where('id', $request->branch_id);
        }
        $branchIds = $branchIdsQuery->pluck('id');

        $drivers = Driver::whereIn('branch_id', $branchIds)
            ->limit(10)
            ->get()
            ->map(function ($driver) {
                return [
                    'driver_id' => $driver->id,
                    'name' => $driver->full_name,
                    'price_rate' => (float) ($driver->price_rate ?? 0),
                    'rating' => (float) ($driver->rating ?? 0),
                    'image' => $driver->image,
                    'location_text' => null,
                ];
            });

        return successResponse($drivers, __('vendor.featured_drivers_retrieved'));
    }

    /**
     * Get reviews
     */
    public function getReviews(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id ?? $request->vendor_id;
        $branchIdsQuery = Branch::where('vendor_id', $vendorId);
        if ($request->has('branch_id')) {
            $branchIdsQuery->where('id', $request->branch_id);
        }
        $branchIds = $branchIdsQuery->pluck('id');

        $uploadService = $this->uploadFilesService;
        $reviews = Order::whereIn('branch_id', $branchIds)
            ->whereNotNull('rating')
            ->with('client')
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 15))
            ->through(function ($order) use ($uploadService) {
                return [
                    'id' => $order->id,
                    'user_name' => $order->client ? $order->client->full_name : 'Anonymous',
                    'user_img' => $order->client ? $uploadService->getFullUrl($order->client->image) : null,
                    'rating' => $order->rating,
                    'comment' => $order->review,
                    'date' => $order->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return successResponse($reviews, __('vendor.reviews_retrieved'));
    }

    /**
     * Pickup leg: nearest to customer. Delivery leg: nearest to branch (laundry).
     */
    private function resolveDriversAvailableAssignmentLeg(Order $order): ?string
    {
        if ($this->orderIsOnDeliveryLeg($order) && $order->needsDeliveryDriver()) {
            return 'delivery';
        }

        if ($order->needsPickupDriver()) {
            return 'pickup';
        }

        return null;
    }

    /**
     * @return array{latitude: float, longitude: float, reference: string}|null
     */
    private function resolveDriversAvailableSortCoordinates(Order $order, string $leg): ?array
    {
        if ($leg === 'delivery') {
            $branch = $order->branch;
            if ($branch?->latitude && $branch?->longitude) {
                return [
                    'latitude' => (float) $branch->latitude,
                    'longitude' => (float) $branch->longitude,
                    'reference' => 'branch',
                ];
            }

            return null;
        }

        $address = $order->pickupAddress ?? $order->deliveryAddress;
        if ($address?->latitude && $address?->longitude) {
            return [
                'latitude' => (float) $address->latitude,
                'longitude' => (float) $address->longitude,
                'reference' => 'customer',
            ];
        }

        return null;
    }

    private function formatDriverLocationText(Driver $driver): ?string
    {
        $lang = app()->getLocale();

        if (method_exists($driver, 'getTranslation')) {
            $text = $driver->getTranslation('location', $lang, false);
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }

            foreach (['ar', 'en'] as $fallbackLang) {
                $text = $driver->getTranslation('location', $fallbackLang, false);
                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            }
        }

        if ($driver->latitude && $driver->longitude) {
            return sprintf('%.5f, %.5f', (float) $driver->latitude, (float) $driver->longitude);
        }

        return null;
    }

    /**
     * Resolve pickup vs delivery assignment from request fields and order leg.
     *
     * @return array{type: ?string, driver_id: ?int}
     */
    private function resolveVendorDriverAssignment(Order $order, Request $request): array
    {
        $pickupDriverId = $request->input('pickup_driver_id');
        $deliveryDriverId = $request->input('delivery_driver_id');
        $genericDriverId = $request->input('driver_id');

        if ($genericDriverId && ! $pickupDriverId && ! $deliveryDriverId) {
            if ($this->orderIsOnDeliveryLeg($order)) {
                $deliveryDriverId = $genericDriverId;
            } else {
                $pickupDriverId = $genericDriverId;
            }
        }

        // Legacy clients may send pickup_driver_id while the order is on the delivery leg.
        if ($pickupDriverId && ! $deliveryDriverId && $this->orderIsOnDeliveryLeg($order) && $order->needsDeliveryDriver()) {
            $deliveryDriverId = $pickupDriverId;
            $pickupDriverId = null;
        }

        if ($deliveryDriverId && $order->needsDeliveryDriver()) {
            return ['type' => 'delivery', 'driver_id' => (int) $deliveryDriverId];
        }

        if ($pickupDriverId && $order->needsPickupDriver()) {
            return ['type' => 'pickup', 'driver_id' => (int) $pickupDriverId];
        }

        return ['type' => null, 'driver_id' => null];
    }

    private function orderIsOnDeliveryLeg(Order $order): bool
    {
        $status = OrderStatus::tryFrom($order->status);
        if (! $status) {
            return false;
        }

        if (in_array($status, OrderStatus::vendorDeliveryDriverAssignableStatuses(), true)) {
            return true;
        }

        return in_array($status, [
            OrderStatus::PICKED_UP,
            OrderStatus::DELIVERED,
        ], true);
    }

    /**
     * Driver already assigned on the active assignment leg — exclude from available list
     * so re-assignment cannot offer the same driver again.
     */
    private function resolveCurrentlyAssignedDriverIdForAvailableList(Order $order): ?int
    {
        $status = OrderStatus::tryFrom($order->status);

        if ($status === OrderStatus::DRIVER_PICKUP_ASSIGNED && $order->pickup_driver_id) {
            return (int) $order->pickup_driver_id;
        }

        if ($status === OrderStatus::DRIVER_DELIVERY_ASSIGNED && $order->delivery_driver_id) {
            return (int) $order->delivery_driver_id;
        }

        // Broader re-assign cases on the same leg (accepted / on the way / postponed, etc.)
        if ($this->orderIsOnDeliveryLeg($order) && $order->delivery_driver_id) {
            return (int) $order->delivery_driver_id;
        }

        if ($order->pickup_driver_id && ! $this->orderIsOnDeliveryLeg($order)) {
            return (int) $order->pickup_driver_id;
        }

        return null;
    }
}
