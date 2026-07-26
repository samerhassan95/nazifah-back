<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Branch\Models\Branch;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Vendor\Support\VendorBranchFilter;

class DriversController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get all drivers (simplified)
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Build query
        $query = Driver::where('vendor_id', $vendorId);

        if (VendorBranchFilter::hasFilter($request)) {
            $branchIds = VendorBranchFilter::resolveIds($request, $vendorId);
            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('branch_id', $branchIds);
            }
        }

        // Get drivers associated with this vendor
        $drivers = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($driver) {
                $lang = app()->getLocale();

                return [
                    'driver_id' => $driver->id,
                    'name' => $driver->getTranslation('full_name', $lang),
                    'rating' => (float) ($driver->rating ?? 0),
                    'image' => $this->uploadFilesService->getFullUrl($driver->image),
                    'is_available' => (bool) $driver->is_available,
                    'branch_id' => $driver->branch_id,
                    'phone' => $driver->phone,
                    'full_name' => $driver->getTranslations('full_name'),
                    'email' => $driver->email,
                    'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                    'longitude' => $driver->longitude ? (float) $driver->longitude : null,
                    'total_orders' => (int) $driver->total_orders,
                ];
            });

        return successResponse($drivers, __('driver.drivers_retrieved'));
    }

    /**
     * Add driver
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required_without_all:name.ar,name.en'],
            'name.ar' => ['required_without:name', 'string', 'max:255'],
            'name.en' => ['required_without:name', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:drivers,email'],
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:drivers,phone'],
            'id_number' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'image_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = (int) $employee->vendor_id;

        if ($request->filled('branch_id')) {
            $branch = Branch::where('id', $request->branch_id)->where('vendor_id', $vendorId)->first();
            if (! $branch) {
                return validationErrorResponse([
                    'branch_id' => [__('driver.branch_not_found_or_not_yours')],
                ]);
            }
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $this->uploadFilesService->uploadImage($request->file('image'), 'drivers/images');
        }

        $imageDocumentPath = null;
        if ($request->hasFile('image_document')) {
            $imageDocumentPath = $this->uploadFilesService->uploadFile(
                $request->file('image_document'),
                'drivers/documents'
            );
        }

        // Handle name - support both formats
        $name = [];
        if ($request->has('name') && is_string($request->name)) {
            $name = [
                'ar' => $request->name,
                'en' => $request->name,
            ];
        } elseif ($request->has('name') && is_array($request->name)) {
            $name = [
                'ar' => $request->name['ar'] ?? $request->name['en'] ?? '',
                'en' => $request->name['en'] ?? $request->name['ar'] ?? '',
            ];
        }

        $driver = Driver::create([
            'vendor_id' => $vendorId,
            'branch_id' => $request->branch_id,
            'full_name' => $name,
            'email' => $request->email,
            'phone' => $request->phone_number,
            'id_number' => $request->id_number,
            'image' => $imagePath,
            'image_document' => $imageDocumentPath,
            'is_available' => true,
        ]);

        if ($request->filled('branch_id')) {
            Branch::find($request->branch_id)?->assignDriver($driver->id);
        }

        return successResponse([
            'driver_id' => $driver->id,
            'name' => $driver->full_name,
            'email' => $driver->email,
            'phone_number' => $driver->phone,
            'is_active' => true,
            'is_available' => true,
        ], __('driver.driver_created'), 201);
    }

    /**
     * Get driver details
     */
    public function show(Request $request, $driverId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $driver = Driver::where('id', $driverId)
            ->where('vendor_id', $vendorId)
            ->with('branch')
            ->first();

        if (! $driver) {
            return notFoundResponse(__('driver.driver_not_found'));
        }

        // Get reviews
        $reviews = Order::where('driver_id', $driverId)
            ->whereNotNull('rating')
            ->with('client')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($order) {
                $clientName = 'Anonymous';
                if ($order->client) {
                    $clientName = $order->client->full_name ?? 'Anonymous';
                }

                return [
                    'user_name' => $clientName,
                    'user_image' => $order->client ? $this->uploadFilesService->getFullUrl($order->client->image) : null,
                    'rating' => $order->rating,
                    'comment' => $order->review,
                    'date' => $order->created_at->format('Y-m-d'),
                ];
            });

        // Get branch assigned to this driver
        $branch = null;
        if ($driver->branch) {
            $branch = [
                'id' => $driver->branch->id,
                'name' => [
                    'ar' => is_array($driver->branch->name) ? ($driver->branch->name['ar'] ?? '') : $driver->branch->name,
                    'en' => is_array($driver->branch->name) ? ($driver->branch->name['en'] ?? '') : $driver->branch->name,
                ],
                'location_text' => is_array($driver->branch->location)
                    ? ($driver->branch->location[app()->getLocale()] ?? $driver->branch->location['en'] ?? $driver->branch->location['ar'] ?? '')
                    : ($driver->branch->location ?? ''),
            ];
        }

        return successResponse([
            'id' => $driver->id,
            'name' => [
                'ar' => is_array($driver->full_name) ? ($driver->full_name['ar'] ?? '') : $driver->full_name,
                'en' => is_array($driver->full_name) ? ($driver->full_name['en'] ?? '') : $driver->full_name,
            ],
            'email' => $driver->email,
            'phone_number' => $driver->phone,
            'national_id' => $driver->id_number,
            'id_number' => $driver->id_number,
            'rating' => (float) ($driver->rating ?? 0),
            'total_orders' => (int) ($driver->total_orders ?? 0),
            'image' => $this->uploadFilesService->getFullUrl($driver->image),
            'image_document' => $this->uploadFilesService->getFullUrl($driver->image_document),
            'is_active' => (bool) $driver->is_available,
            'is_available' => (bool) $driver->is_available,
            'latitude' => $driver->latitude ? (float) $driver->latitude : null,
            'longitude' => $driver->longitude ? (float) $driver->longitude : null,
            'branch' => $branch,
            'reviews' => $reviews,
            'created_at' => $driver->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $driver->updated_at?->format('Y-m-d H:i:s'),
        ], __('driver.driver_details_retrieved'));
    }

    /**
     * Toggle driver status
     */
    public function toggleStatus(Request $request, $driverId): JsonResponse
    {
        $vendorId = (int) $request->user()->vendor_id;

        $driver = Driver::where('id', $driverId)->where('vendor_id', $vendorId)->first();

        if (! $driver) {
            return notFoundResponse(__('driver.driver_not_found_or_not_yours'));
        }

        $driver->update([
            'is_available' => ! $driver->is_available,
        ]);

        return successResponse([
            'driver_id' => $driver->id,
            'is_active' => $driver->is_available,
        ], __('driver.driver_status_updated'));
    }

    /**
     * Get driver revenues
     */
    public function getDriverRevenues(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = VendorBranchFilter::resolveIds($request, $vendorId);

        $revenues = Order::whereIn('branch_id', $branchIds)
            ->whereNotNull('driver_id')
            ->where('status', OrderStatus::COMPLETED->value)
            ->with(['driver', 'branch'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'title' => 'Order #'.$order->order_number,
                    'order_id' => $order->id,
                    'amount_paid' => (float) $order->final_amount,
                    'date' => $order->created_at->format('Y-m-d'),
                    'payment_method' => $order->payment_method ?? 'N/A',
                    'name' => $order->branch ? $order->branch->name : null,
                ];
            });

        return successResponse($revenues, __('driver.driver_revenues_retrieved'));
    }

    /**
     * Get specific driver revenues with reviews
     * GET /api/v1/vendor/drivers/{driverId}/revenues
     */
    public function getSpecificDriverRevenues(Request $request, $driverId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = \Modules\Branch\Models\Branch::where('vendor_id', $vendorId)->pluck('id');

        // Check if driver belongs to this vendor
        $driver = Driver::where('id', $driverId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $driver) {
            return notFoundResponse(__('driver.driver_not_found_or_not_yours'));
        }

        // Get orders for this specific driver
        $orders = Order::whereIn('branch_id', $branchIds)
            ->where('driver_id', $driverId)
            ->where('status', OrderStatus::COMPLETED->value)
            ->with(['client', 'branch', 'items.piece', 'items.service'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                // Get first item details
                $firstItem = $order->items->first();
                $itemName = 'Order';
                if ($firstItem && $firstItem->piece) {
                    $itemName = $firstItem->piece->getTranslation('name', app()->getLocale());
                }

                $result = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'title' => $itemName,
                    'amount_paid' => (float) $order->final_amount,
                    'delivery_fee' => (float) ($order->delivery_fee ?? 0),
                    'date' => $order->created_at->format('Y-m-d'),
                    'payment_method' => $order->payment_method ?? 'cash',
                    'branch_name' => $order->branch ? $order->branch->getTranslation('name', app()->getLocale()) : null,
                    'customer_name' => $order->client ? $order->client->full_name : 'Unknown',
                    'customer_image' => $order->client ? $this->uploadFilesService->getFullUrl($order->client->image) : null,
                ];

                // Add review if exists
                if ($order->rating || $order->review) {
                    $result['review'] = [
                        'rating' => (float) ($order->rating ?? 0),
                        'comment' => $order->review,
                        'date' => $order->updated_at->format('Y-m-d H:i:s'),
                    ];
                } else {
                    $result['review'] = null;
                }

                return $result;
            });

        // Calculate statistics
        $totalRevenue = $orders->sum('amount_paid');
        $totalOrders = $orders->count();
        $averageRating = $orders->where('review', '!=', null)->avg('review.rating') ?? 0;
        $totalReviews = $orders->where('review', '!=', null)->count();

        return successResponse([
            'driver_id' => $driver->id,
            'driver_name' => $driver->full_name,
            'driver_image' => $this->uploadFilesService->getFullUrl($driver->image),
            'statistics' => [
                'total_revenue' => (float) $totalRevenue,
                'total_orders' => $totalOrders,
                'average_rating' => round((float) $averageRating, 2),
                'total_reviews' => $totalReviews,
            ],
            'orders' => $orders,
        ], __('driver.driver_revenues_retrieved'));
    }

    /**
     * Get driver delivered orders
     */
    public function getDriverDelivered(Request $request, $driverId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = \Modules\Branch\Models\Branch::where('vendor_id', $vendorId)->pluck('id');

        $driver = Driver::where('id', $driverId)->where('vendor_id', $vendorId)->first();
        if (! $driver) {
            return notFoundResponse(__('driver.driver_not_found_or_not_yours'));
        }

        $orders = Order::whereIn('branch_id', $branchIds)
            ->where('driver_id', $driverId)
            ->where('status', OrderStatus::DELIVERED->value)
            ->with(['client', 'pickupAddress', 'branch.vendor', 'items.service'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                $firstItem = $order->items->first();
                $serviceName = 'Service';
                if ($firstItem && $firstItem->service) {
                    $serviceName = is_array($firstItem->service->service_name)
                        ? ($firstItem->service->service_name[app()->getLocale()] ?? $firstItem->service->service_name['en'] ?? 'Service')
                        : $firstItem->service->service_name;
                }
                $vendor = $order->branch ? $order->branch->vendor : null;

                return [
                    'order_id' => $order->id,
                    'service_name' => $serviceName,
                    'date' => $order->created_at->format('Y-m-d'),
                    'customer_name' => $order->client->full_name ?? 'Unknown',
                    'Distance' => null,
                    'address' => $order->pickupAddress ? $order->pickupAddress->street_name : null,
                    'laundry_name' => $vendor ? (is_array($vendor->name) ? ($vendor->name[app()->getLocale()] ?? $vendor->name['en'] ?? null) : $vendor->name) : null,
                    'delivery_fee' => (float) $order->delivery_fee,
                ];
            });

        $lang = app()->getLocale();

        return successResponse([
            'driver_id' => $driver->id,
            'name' => [
                'ar' => is_array($driver->full_name) ? ($driver->full_name['ar'] ?? '') : $driver->full_name,
                'en' => is_array($driver->full_name) ? ($driver->full_name['en'] ?? '') : $driver->full_name,
            ],
            'driver_name' => $driver->getTranslation('full_name', $lang),
            'phone_number' => $driver->phone,
            'national_id' => $driver->id_number,
            'id_number' => $driver->id_number,
            'image' => $this->uploadFilesService->getFullUrl($driver->image),
            'image_document' => $this->uploadFilesService->getFullUrl($driver->image_document),
            'delivered_orders' => $orders,
        ], __('driver.delivered_orders_retrieved'));
    }

    /**
     * Get report revenues
     */
    public function getReportRevenues(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = VendorBranchFilter::resolveIds($request, $vendorId);

        $revenues = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::COMPLETED->value)
            ->with('branch')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'Title' => 'Order #'.$order->order_number,
                    'sub_title' => 'Completed order',
                    'order_id' => $order->id,
                    'amount_paid' => (float) $order->final_amount,
                    'date' => $order->created_at->format('Y-m-d'),
                    'payment_method' => $order->payment_method ?? 'N/A',
                    'name' => $order->branch ? $order->branch->name : null,
                ];
            });

        return successResponse($revenues, __('driver.report_revenues_retrieved'));
    }

    /**
     * Filter revenues
     */
    public function getRevenuesFilter(Request $request): JsonResponse
    {
        VendorBranchFilter::normalizeRequest($request);

        $validator = Validator::make($request->all(), array_merge(
            VendorBranchFilter::validationRules(),
            [
                'vendor_id' => ['nullable', 'exists:vendors,id'],
                'start_date' => ['nullable', 'date'],
                'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            ]
        ));

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $request->vendor_id ?? $employee->vendor_id;
        $branchIds = VendorBranchFilter::resolveIds($request, (int) $vendorId);

        $query = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::COMPLETED->value);

        if ($request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $revenues = $query->with('branch')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'Title' => 'Order #'.$order->order_number,
                    'sub_title' => 'Completed order',
                    'order_id' => $order->id,
                    'amount_paid' => (float) $order->final_amount,
                    'date' => $order->created_at->format('Y-m-d'),
                    'payment_method' => $order->payment_method ?? 'N/A',
                    'name' => $order->branch ? $order->branch->name : null,
                ];
            });

        return successResponse($revenues, __('driver.filtered_revenues_retrieved'));
    }

    /**
     * Filter drivers by vendor
     */
    public function filterDrivers(Request $request): JsonResponse
    {
        VendorBranchFilter::normalizeRequest($request);

        $validator = Validator::make($request->all(), array_merge(
            VendorBranchFilter::validationRules(),
            [
                'vendor_id' => ['nullable', 'exists:vendors,id'],
            ]
        ));

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $request->vendor_id ?? $employee->vendor_id;

        $query = Driver::where('vendor_id', $vendorId);

        if (VendorBranchFilter::hasFilter($request)) {
            $branchIds = VendorBranchFilter::resolveIds($request, (int) $vendorId);
            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('branch_id', $branchIds);
            }
        }

        $drivers = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($driver) {
                $lang = app()->getLocale();

                return [
                    'driver_id' => $driver->id,
                    'name' => $driver->getTranslation('full_name', $lang),
                    'rating' => (float) ($driver->rating ?? 0),
                    'image' => $this->uploadFilesService->getFullUrl($driver->image),
                    'is_available' => (bool) $driver->is_available,
                    'branch_id' => $driver->branch_id,
                    'phone' => $driver->phone,
                    'full_name' => $driver->getTranslations('full_name'),
                    'email' => $driver->email,
                    'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                    'longitude' => $driver->longitude ? (float) $driver->longitude : null,
                    'total_orders' => (int) $driver->total_orders,
                ];
            });

        return successResponse($drivers, __('driver.drivers_retrieved'));
    }

    /**
     * Get drivers NOT assigned to a given branch (candidates to assign to it).
     * GET /api/v1/vendor/drivers/available?branch_id=38
     */
    public function available(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $query = Driver::where('vendor_id', $vendorId);

        // Exclude drivers already assigned to this branch
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->branch_id;
            $query->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')
                    ->orWhere('branch_id', '!=', $branchId);
            });
        }

        $drivers = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($driver) {
                $lang = app()->getLocale();

                return [
                    'driver_id' => $driver->id,
                    'name' => $driver->getTranslation('full_name', $lang),
                    'rating' => (float) ($driver->rating ?? 0),
                    'image' => $this->uploadFilesService->getFullUrl($driver->image),
                    'is_available' => (bool) $driver->is_available,
                    'branch_id' => $driver->branch_id,
                    'phone' => $driver->phone,
                    'full_name' => $driver->getTranslations('full_name'),
                    'email' => $driver->email,
                    'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                    'longitude' => $driver->longitude ? (float) $driver->longitude : null,
                    'total_orders' => (int) $driver->total_orders,
                ];
            });

        return successResponse($drivers, __('driver.drivers_retrieved'));
    }

    /**
     * Assign driver to branch
     * POST /api/v1/vendor/drivers/{driverId}/assign-branch
     */
    public function assignToBranch(Request $request, $driverId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Validate input
        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $driver = Driver::where('id', $driverId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $driver) {
            return notFoundResponse(__('driver.driver_not_found_or_not_yours'));
        }

        // Check if branch belongs to this vendor
        $branch = \Modules\Branch\Models\Branch::where('id', $request->branch_id)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return errorResponse(__('driver.branch_not_found_or_not_yours'), null, 404);
        }

        // Assign driver to branch
        $branch->assignDriver($driver->id);

        return successResponse([
            'driver_id' => $driver->id,
            'driver_name' => $driver->full_name,
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
            ],
        ], __('driver.driver_assigned_to_branch'), 200);
    }

    /**
     * Update driver
     * PUT /api/v1/vendor/drivers/{driverId}
     */
    public function update(Request $request, $driverId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $driver = Driver::where('id', $driverId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $driver) {
            return notFoundResponse(__('driver.driver_not_found_or_not_yours'));
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required_without_all:name.ar,name.en'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:drivers,email,'.$driverId],
            'phone_number' => ['sometimes', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:drivers,phone,'.$driverId],
            'id_number' => ['sometimes', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'image_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'is_available' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $data = [];

        if ($request->has('name')) {
            if (is_string($request->name)) {
                $data['full_name'] = [
                    'ar' => $request->name,
                    'en' => $request->name,
                ];
            } elseif (is_array($request->name)) {
                $data['full_name'] = [
                    'ar' => $request->name['ar'] ?? $request->name['en'] ?? '',
                    'en' => $request->name['en'] ?? $request->name['ar'] ?? '',
                ];
            }
        }

        if ($request->has('email')) {
            $data['email'] = $request->email;
        }

        if ($request->has('phone_number')) {
            $data['phone'] = $request->phone_number;
        }

        if ($request->has('id_number')) {
            $data['id_number'] = $request->id_number;
        }

        if ($request->has('branch_id')) {
            // Verify branch belongs to vendor
            $branch = \Modules\Branch\Models\Branch::where('id', $request->branch_id)
                ->where('vendor_id', $vendorId)
                ->first();

            if (! $branch) {
                return errorResponse(__('driver.branch_not_found_or_not_yours'), null, 400);
            }

            $data['branch_id'] = $request->branch_id;
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadFilesService->uploadImage(
                $request->file('image'),
                'drivers/images',
                $driver->getRawOriginal('image')
            );
        }

        if ($request->hasFile('image_document')) {
            $data['image_document'] = $this->uploadFilesService->uploadFile(
                $request->file('image_document'),
                'drivers/documents',
                $driver->image_document
            );
        }

        $driver->update($data);
        $driver->refresh();
        $driver->load('branch');

        return successResponse([
            'driver_id' => $driver->id,
            'name' => $driver->full_name,
            'email' => $driver->email,
            'phone_number' => $driver->phone,
            'national_id' => $driver->id_number,
            'id_number' => $driver->id_number,
            'rating' => (float) ($driver->rating ?? 0),
            'image' => $this->uploadFilesService->getFullUrl($driver->image),
            'image_document' => $this->uploadFilesService->getFullUrl($driver->image_document),
            'is_active' => (bool) $driver->is_available,
            'branch' => $driver->branch ? [
                'id' => $driver->branch->id,
                'name' => $driver->branch->name,
            ] : null,
        ], __('driver.driver_updated'));
    }

    /**
     * Delete driver
     * DELETE /api/v1/vendor/drivers/{driverId}
     */
    public function destroy(Request $request, $driverId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Check if driver belongs to this vendor
        $driver = Driver::where('id', $driverId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $driver) {
            return notFoundResponse(__('driver.driver_not_found_or_not_yours'));
        }

        $activeStatuses = array_values(array_filter(
            OrderStatus::cases(),
            fn (OrderStatus $status) => ! in_array($status, [
                OrderStatus::DELIVERED,
                OrderStatus::COMPLETED,
                OrderStatus::CANCELLED,
            ], true)
        ));
        $activeStatuses = array_map(fn (OrderStatus $s) => $s->value, $activeStatuses);

        $hasActiveOrders = Order::whereIn('branch_id', Branch::where('vendor_id', $vendorId)->pluck('id'))
            ->where(function ($q) use ($driverId) {
                $q->where('driver_id', $driverId)
                    ->orWhere('pickup_driver_id', $driverId)
                    ->orWhere('delivery_driver_id', $driverId);
            })
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($hasActiveOrders) {
            return validationErrorResponse([
                'driver_id' => [__('driver.cannot_delete_driver_with_active_orders')],
            ]);
        }

        $driver->tokens()->delete();

        if ($driver->image) {
            $this->uploadFilesService->deleteFile($driver->image);
        }

        $driver->delete();

        return successResponse([
            'driver_id' => (int) $driverId,
            'deleted' => true,
        ], __('driver.driver_deleted'));
    }
}
