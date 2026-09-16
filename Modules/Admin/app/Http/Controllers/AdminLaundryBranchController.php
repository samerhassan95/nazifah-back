<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Branch\Services\BranchWorkingHoursService;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\ServiceAddition;
use Modules\Vendor\Models\Vendor;

class AdminLaundryBranchController extends Controller
{
    protected $uploadFilesService;

    public function __construct(
        UploadFilesService $uploadFilesService,
        protected BranchWorkingHoursService $workingHoursService
    ) {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Display a listing of branches for a specific vendor.
     * GET /admin/laundries/branches?vendor_id=XX
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');
        $perPage = $request->input('per_page', 15);
        $lang = app()->getLocale();

        if (! $vendorId) {
            return errorResponse('Vendor ID is required', null, 400);
        }

        $vendor = Vendor::find($vendorId);
        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        $branches = Branch::where('vendor_id', $vendorId)
            ->with(['vendor'])
            ->latest()
            ->paginate($perPage);

        // Header Stats for the Vendor: total branches + the top branch by
        // orders, by revenue, and by rating.
        $branchesStatsQuery = Branch::where('vendor_id', $vendorId)
            ->withCount('orders')
            ->withSum(['orders as revenue' => function ($q) {
                $q->whereHas('paymentTransactions', function ($q2) {
                    $q2->where('status', 'completed');
                });
            }], 'final_amount');

        $topOrdersBranch = (clone $branchesStatsQuery)->orderByDesc('orders_count')->first();
        $topRevenueBranch = (clone $branchesStatsQuery)->orderByDesc('revenue')->first();
        $topRatingBranch = Branch::where('vendor_id', $vendorId)
            ->whereNotNull('rating')
            ->orderByDesc('rating')
            ->first();

        $states = [
            'total_branches' => $branches->total(),
            'Top_orders' => (int) ($topOrdersBranch->orders_count ?? 0),
            'Top_revenues' => (float) ($topRevenueBranch->revenue ?? 0),
            'Top_rated' => (float) ($topRatingBranch->rating ?? 0),
        ];

        $branchesData = collect($branches->items())->map(fn ($branch) => $this->formatBranch($branch));

        return successResponse([
            'States' => $states,
            'Branches' => $branchesData,
            'pagination' => [
                'total' => $branches->total(),
                'per_page' => $branches->perPage(),
                'current_page' => $branches->currentPage(),
                'last_page' => $branches->lastPage(),
            ],
        ], 'Branches retrieved successfully');
    }

    /**
     * Store a newly created branch.
     * POST /admin/laundries/branches
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'Branch_logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'name' => 'required|array',
            'name.ar' => 'required|string',
            'name.en' => 'required|string',
            'description' => 'nullable|array',
            'description.ar' => 'nullable|string',
            'description.en' => 'nullable|string',
            'address' => 'required|array',
            'address.ar' => 'required|string',
            'address.en' => 'required|string',
            'national_address' => 'nullable|string',
            'Phone' => 'required|string',
            'land_phone' => 'nullable|string',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'home_pickup' => 'nullable|boolean',
            'self_dropoff' => 'nullable|boolean',
            'home_delivery' => 'nullable|boolean',
            'self_pickup' => 'nullable|boolean',
        ]);

        $branch = new Branch;
        $branch->vendor_id = $validated['vendor_id'];

        // Handle Translations
        $branch->setTranslation('name', 'ar', $validated['name']['ar']);
        $branch->setTranslation('name', 'en', $validated['name']['en']);
        $branch->setTranslation('location', 'ar', $validated['address']['ar']);
        $branch->setTranslation('location', 'en', $validated['address']['en']);
        $branch->setTranslation('description', 'ar', $validated['description']['ar']);
        $branch->setTranslation('description', 'en', $validated['description']['en']);

        $branch->national_address = $validated['national_address'] ?? null;
        $branch->phone_number = $validated['Phone'];
        $branch->land_phone = $validated['land_phone'] ?? null;
        $branch->latitude = $validated['lat'];
        $branch->longitude = $validated['lng'];
        $branch->home_pickup = $request->boolean('home_pickup');
        $branch->self_dropoff = $request->boolean('self_dropoff');
        $branch->home_delivery = $request->boolean('home_delivery');
        $branch->self_pickup = $request->boolean('self_pickup');
        $branch->is_active = true;

        if ($request->hasFile('Branch_logo')) {
            $branch->store_front = $this->uploadFilesService->uploadImage(
                $request->file('Branch_logo'), 'branches/logos'
            );
        }

        $branch->save();

        return successResponse($this->formatBranch($branch->load('vendor')), 'Branch created successfully', 201);
    }

    /**
     * Display the specified branch.
     * GET /admin/laundries/branches/{id}
     */
    public function show(int $id): JsonResponse
    {
        $branch = Branch::with('vendor')->find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        return successResponse($this->formatBranch($branch), 'Branch retrieved successfully');
    }

    /**
     * Update the specified branch.
     * PUT/PATCH /admin/laundries/branches/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $validated = $request->validate([
            'Branch_logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'name' => 'sometimes|array',
            'name.ar' => 'sometimes|string',
            'name.en' => 'sometimes|string',
            'address' => 'sometimes|array',
            'address.ar' => 'sometimes|string',
            'address.en' => 'sometimes|string',
            'national_address' => 'sometimes|nullable|string',
            'description' => 'sometimes|array',
            'description.ar' => 'sometimes|string',
            'description.en' => 'sometimes|string',
            'Phone' => 'sometimes|string',
            'land_phone' => 'sometimes|nullable|string',
            'lat' => 'sometimes|numeric|between:-90,90',
            'lng' => 'sometimes|numeric|between:-180,180',
            'home_pickup' => 'sometimes|boolean',
            'self_dropoff' => 'sometimes|boolean',
            'home_delivery' => 'sometimes|boolean',
            'self_pickup' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        // Dynamic Translation Updates
        if ($request->has('name')) {
            $branch->setTranslation('name', 'ar', $validated['name']['ar']);
        }
        if ($request->has('name')) {
            $branch->setTranslation('name', 'en', $validated['name']['en']);
        }
        if ($request->has('address')) {
            $branch->setTranslation('location', 'ar', $validated['address']['ar']);
        }
        if ($request->has('address')) {
            $branch->setTranslation('location', 'en', $validated['address']['en']);
        }
        if ($request->has('description')) {
            $branch->setTranslation('description', 'ar', $validated['description']['ar']);
        }
        if ($request->has('description')) {
            $branch->setTranslation('description', 'en', $validated['description']['en']);
        }

        if ($request->has('national_address')) {
            $branch->national_address = $validated['national_address'];
        }
        if ($request->has('Phone')) {
            $branch->phone_number = $validated['Phone'];
        }
        if ($request->has('land_phone')) {
            $branch->land_phone = $validated['land_phone'];
        }
        if ($request->has('lat')) {
            $branch->latitude = $validated['lat'];
        }
        if ($request->has('lng')) {
            $branch->longitude = $validated['lng'];
        }
        foreach (['home_pickup', 'self_dropoff', 'home_delivery', 'self_pickup'] as $deliveryFlag) {
            if ($request->has($deliveryFlag)) {
                $branch->{$deliveryFlag} = $request->boolean($deliveryFlag);
            }
        }
        if ($request->has('is_active')) {
            $branch->is_active = $validated['is_active'];
        }

        if ($request->hasFile('Branch_logo')) {
            $branch->store_front = $this->uploadFilesService->uploadImage(
                $request->file('Branch_logo'), 'branches/logos', $branch->store_front
            );
        }

        $branch->save();

        return successResponse($this->formatBranch($branch->load('vendor')), 'Branch updated successfully');
    }

    /**
     * Toggle branch activation status
     * PATCH /admin/laundries/branches/{id}/toggle
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $branch = Branch::find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $branch->is_active = ! $branch->is_active;
        $branch->save();

        return successResponse([
            'id' => $branch->id,
            'is_active' => $branch->is_active,
        ], 'Branch status toggled successfully');
    }

    /**
     * Remove the specified branch.
     * DELETE /admin/laundries/branches/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $branch = Branch::find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        // Optional: Check for existing orders before deleting
        if ($branch->orders()->exists()) {
            return errorResponse('Cannot delete branch with existing orders. Deactivate it instead.', null, 400);
        }

        $branch->delete();

        return successResponse(null, 'Branch deleted successfully');
    }

    /**
     * Get a branch's working hours.
     * GET /admin/laundries/branches/{id}/working-hours
     */
    public function getWorkingHours(int $id): JsonResponse
    {
        $branch = Branch::with('workingHourShifts')->find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        return successResponse([
            'branch_id' => $branch->id,
            'working_hours' => $branch->getApiWorkingHours(),
        ], 'Working hours retrieved successfully');
    }

    /**
     * Replace a branch's full working-hours schedule.
     * PUT /admin/laundries/branches/{id}/working-hours
     */
    public function saveWorkingHours(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $validated = $request->validate([
            'working_hours' => ['required', 'array'],
        ]);

        $errors = $this->workingHoursService->validate($validated['working_hours']);
        if ($errors !== []) {
            return validationErrorResponse($errors);
        }

        $this->workingHoursService->sync($branch, $validated['working_hours']);
        $branch->load('workingHourShifts');

        return successResponse([
            'branch_id' => $branch->id,
            'working_hours' => $branch->getApiWorkingHours(),
        ], 'Working hours updated successfully');
    }

    /**
     * Full branch detail page: branch info, working hours, stats, the
     * branch's own drivers/services (with live ratings), and the vendor's
     * pieces/additional services catalog. Composes several existing
     * endpoints into one call for the branch detail screen.
     * GET /admin/laundries/branches/{id}/detail
     */
    public function detail(int $id): JsonResponse
    {
        $branch = Branch::with('vendor')->find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $locale = app()->getLocale();

        $stats = [
            'orders_balance' => (float) Order::where('branch_id', $id)
                ->whereHas('paymentTransactions', fn ($q) => $q->where('status', 'completed'))
                ->sum('final_amount'),
            'total_drivers' => Driver::where('branch_id', $id)->count(),
            'total_orders' => Order::where('branch_id', $id)->count(),
        ];

        $drivers = Driver::where('branch_id', $id)->get()->map(fn ($driver) => [
            'id' => $driver->id,
            'Driver_image' => $driver->image,
            'Driver_name' => $driver->getTranslation('full_name', $locale) ?? $driver->full_name,
            'Phone' => $driver->phone,
        ]);

        $services = $branch->services()->where('services.is_active', true)->get()->map(function ($service) use ($branch) {
            $rating = Order::whereHas('items.service', function ($q) use ($service) {
                $q->where('services.id', $service->id);
            })->where('branch_id', $branch->id)->whereNotNull('rating')->avg('rating') ?? 0;

            $iconPath = null;
            if ($service->pivot->icon_id) {
                $icon = \Modules\Admin\Models\Icon::find($service->pivot->icon_id);
                $iconPath = $icon ? $icon->full_path : null;
            }
            if (! $iconPath && $service->iconRelation) {
                $iconPath = $service->iconRelation->full_path;
            }

            return [
                'service_id' => $service->id,
                'name' => $service->getTranslation('service_name', app()->getLocale()),
                'icon' => $iconPath,
                'rating' => round((float) $rating, 2),
            ];
        })->values();

        $pieces = Piece::with('iconRelation')->where('vendor_id', $branch->vendor_id)->get()->map(fn ($piece) => [
            'id' => $piece->id,
            'name' => $piece->getTranslation('name', $locale),
            'icon' => $piece->iconRelation?->full_path,
        ]);

        $additionalServices = ServiceAddition::where('vendor_id', $branch->vendor_id)->get()->map(function ($addition) use ($locale) {
            $rating = Order::whereHas('items.additionalServicesRelation', function ($q) use ($addition) {
                $q->where('service_additions.id', $addition->id);
            })->whereNotNull('rating')->avg('rating') ?? 0;

            return [
                'id' => $addition->id,
                'name' => $addition->getTranslation('name', $locale),
                'price' => (float) $addition->price,
                'rating' => round((float) $rating, 2),
                'icon' => $addition->iconRelation?->full_path,
            ];
        });

        return successResponse([
            'branch' => $this->formatBranch($branch),
            'stats' => $stats,
            'working_hours' => $branch->getApiWorkingHours(),
            'drivers' => $drivers,
            'services' => $services,
            'pieces' => $pieces,
            'additional_services' => $additionalServices,
        ], 'Branch detail retrieved successfully');
    }

    /**
     * Standardized Formatter for Branch Data
     */
    private function formatBranch($branch): array
    {
        $lang = app()->getLocale();

        return [
            'id' => $branch->id,
            'Vendor' => [
                'id' => $branch->vendor_id,
                'name' => $branch->vendor ? $branch->vendor->getTranslation('name', $lang) : null,
            ],
            'Branch_logo' => $branch->store_front ? (str_starts_with($branch->store_front, 'http') ? $branch->store_front : config('app.url').$branch->store_front) : null,
            'name' => [
                'ar' => $branch->getTranslation('name', 'ar'),
                'en' => $branch->getTranslation('name', 'en'),
                'current' => $branch->getTranslation('name', $lang),
            ],
            'description' => [
                'ar' => $branch->getTranslation('description', 'ar'),
                'en' => $branch->getTranslation('description', 'en'),
                'current' => $branch->getTranslation('description', $lang),
            ],
            'address' => [
                'ar' => $branch->getTranslation('location', 'ar'),
                'en' => $branch->getTranslation('location', 'en'),
                'current' => $branch->getTranslation('location', $lang),
            ],
            'National_Address' => $branch->national_address,
            'Phone' => $branch->phone_number,
            'Land_Phone' => $branch->land_phone,
            'Latitude' => $branch->latitude !== null ? (float) $branch->latitude : null,
            'Longitude' => $branch->longitude !== null ? (float) $branch->longitude : null,
            'Home_Pickup' => (bool) $branch->home_pickup,
            'Self_Dropoff' => (bool) $branch->self_dropoff,
            'Home_Delivery' => (bool) $branch->home_delivery,
            'Self_Pickup' => (bool) $branch->self_pickup,
            'Is_Active' => (bool) $branch->is_active,
            'Created_at' => $branch->created_at ? $branch->created_at->format('Y-m-d') : null,
        ];
    }
}
