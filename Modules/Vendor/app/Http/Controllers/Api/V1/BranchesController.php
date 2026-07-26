<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Branch\Services\BranchWorkingHoursService;
use Modules\Order\Models\Order;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use Modules\Service\Support\ServiceAdditionBranchOffering;
use Modules\Vendor\Models\VendorBankAccount;
use Modules\Zone\Models\Zone;

class BranchesController extends Controller
{
    protected $uploadFilesService;

    protected BranchWorkingHoursService $workingHoursService;

    public function __construct(
        UploadFilesService $uploadFilesService,
        BranchWorkingHoursService $workingHoursService
    ) {
        $this->uploadFilesService = $uploadFilesService;
        $this->workingHoursService = $workingHoursService;
    }

    /**
     * Format zone for API response (location/zone data)
     */
    private function formatZoneForResponse(?Zone $zone): ?array
    {
        if (! $zone) {
            return null;
        }

        return [
            'id' => $zone->id,
            'name' => $zone->getTranslation('name', app()->getLocale()),
            'name_ar' => $zone->getTranslation('name', 'ar'),
            'name_en' => $zone->getTranslation('name', 'en'),
            'code' => $zone->code,
            'is_active' => (bool) $zone->is_active,
            'delivery_fee' => $zone->delivery_fee !== null ? (float) $zone->delivery_fee : null,
            'minimum_order' => $zone->minimum_order !== null ? (float) $zone->minimum_order : null,
            'zone_color' => $zone->zone_color,
        ];
    }

    /**
     * Get branches list
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $lang = app()->getLocale();

        $branches = Branch::where('vendor_id', $vendorId)
            ->with(['zone', 'workingHourShifts'])
            ->withCount(['orders', 'pieces', 'services', 'drivers'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($branch) {
                $branchData = [
                    'id' => $branch->id,
                    'vendor_id' => $branch->vendor_id,
                    'name' => [
                        'ar' => $branch->getTranslation('name', 'ar'),
                        'en' => $branch->getTranslation('name', 'en'),
                    ],
                    'address' => [
                        'ar' => $branch->getTranslation('location', 'ar'),
                        'en' => $branch->getTranslation('location', 'en'),
                    ],
                    'address_text' => $branch->getTranslation('location', app()->getLocale()) ?? '',
                    'phone_number' => $branch->phone_number,
                    'land_phone' => $branch->land_phone,
                    'latitude' => $branch->latitude ? (float) $branch->latitude : null,
                    'longitude' => $branch->longitude ? (float) $branch->longitude : null,
                    'rating' => $branch->rating ? (float) $branch->rating : 0,
                    'rate_count' => $branch->rate_count ?? 0,
                    'zone' => $this->formatZoneForResponse($branch->zone),
                    'working_hours' => $branch->getApiWorkingHours(),
                ];

                $branchData['home_pickup'] = (bool) $branch->home_pickup;
                $branchData['self_dropoff'] = (bool) $branch->self_dropoff;
                $branchData['home_delivery'] = (bool) $branch->home_delivery;
                $branchData['self_pickup'] = (bool) $branch->self_pickup;

                return array_merge($branchData, [
                    'image_logo' => $this->uploadFilesService->getFullUrl($branch->logo ?? null),
                    'image_cover' => $this->uploadFilesService->getFullUrl($branch->store_front),
                    'orders_count' => $branch->orders_count ?? 0,
                    'pieces_count' => $branch->pieces_count ?? 0,
                    'services_count' => $branch->services_count ?? 0,
                    'drivers_count' => $branch->drivers_count ?? 0,
                    'created_at' => $branch->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $branch->updated_at?->format('Y-m-d H:i:s'),
                ]);
            });

        return successResponse($branches, __('branch.branches_retrieved'));
    }

    /**
     * Get branch details
     */
    public function show(Request $request, $branchId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->with(['zone', 'workingHourShifts'])
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        // Live rating summary derived from the orders placed at this branch.
        // A customer review is stored on the order itself (orders.rating 1-5 +
        // orders.review) once the order is completed and rated, so the branch's
        // real rating is the average over its rated orders.
        $ratingAggregate = Order::where('branch_id', $branch->id)
            ->whereNotNull('rating')
            ->selectRaw('COUNT(*) as rate_count, AVG(rating) as avg_rating')
            ->first();

        $rateCount = (int) ($ratingAggregate->rate_count ?? 0);
        $avgRating = round((float) ($ratingAggregate->avg_rating ?? 0), 2);

        $branchInfo = [
            'address' => [
                'ar' => $branch->getTranslation('location', 'ar') ?? '',
                'en' => $branch->getTranslation('location', 'en') ?? '',
            ],
            'address_text' => $branch->getTranslation('location', app()->getLocale()) ?? '',
            'Phone_number' => $branch->phone_number,
            'land_phone' => $branch->land_phone,
            'location_gps' => [
                'lat' => (float) $branch->latitude,
                'long' => (float) $branch->longitude,
            ],
            'zone' => $this->formatZoneForResponse($branch->zone),
            'working_hours' => $branch->getApiWorkingHours(),
            'National_address' => $branch->national_address,
            'rating' => $avgRating,
            'rate_count' => $rateCount,
            'description' => [
                'ar' => $branch->getTranslation('description', 'ar'),
                'en' => $branch->getTranslation('description', 'en'),
            ],
            'is_active' => (bool) $branch->is_active,
        ];
        $branchInfo['home_pickup'] = (bool) $branch->home_pickup;
        $branchInfo['self_dropoff'] = (bool) $branch->self_dropoff;
        $branchInfo['home_delivery'] = (bool) $branch->home_delivery;
        $branchInfo['self_pickup'] = (bool) $branch->self_pickup;

        return successResponse([
            'branch_id' => $branch->id,
            'vendor_id' => $branch->vendor_id,
            'name' => [
                'ar' => $branch->getTranslation('name', 'ar'),
                'en' => $branch->getTranslation('name', 'en'),
            ],
            'image_cover' => $this->uploadFilesService->getFullUrl($branch->store_front),
            'image_logo' => $this->uploadFilesService->getFullUrl($branch->logo),
            'branch_info' => $branchInfo,
            'bank_account' => $this->resolveBranchBankAccount($branch),
            'reviews' => $this->getBranchReviews($branch),
        ], __('branch.branch_retrieved'));
    }

    /**
     * Resolve the bank account that applies to a branch.
     *
     * A branch is paid through either its own branch-private account
     * (vendor_bank_accounts.branch_id = branch) or, if none exists, the general
     * vendor-wide account (branch_id IS NULL). Returns null when neither exists.
     */
    private function resolveBranchBankAccount(Branch $branch): ?array
    {
        $account = VendorBankAccount::with('bank')
            ->where('vendor_id', $branch->vendor_id)
            ->where(function ($q) use ($branch) {
                $q->where('branch_id', $branch->id)
                    ->orWhereNull('branch_id');
            })
            // branch-private first (branch_id IS NULL evaluates to 0 for those),
            // then most recent.
            ->orderByRaw('branch_id IS NULL')
            ->latest('id')
            ->first();

        if (! $account) {
            return null;
        }

        return [
            'id' => $account->id,
            'bank_id' => $account->bank_id,
            'bank_name' => $account->bank?->name ?? $account->bank_name,
            'iban_number' => $account->iban_number,
            'account_holder' => $account->account_holder,
            'iban_document_url' => $this->uploadFilesService->getFullUrl($account->iban_document),
            'is_branch_account' => $account->isBranchAccount(),
            'status' => $account->status,
            'vendor_status' => $account->vendor_status,
            'is_fully_approved' => $account->isFullyApproved(),
        ];
    }

    /**
     * Recent customer reviews for a branch.
     *
     * Reviews are the rated orders for this branch (orders.rating + orders.review),
     * enriched with the reviewing client's name and avatar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getBranchReviews(Branch $branch, int $limit = 20): array
    {
        $locale = app()->getLocale();

        return Order::where('branch_id', $branch->id)
            ->whereNotNull('rating')
            ->with('client')
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(function (Order $order) use ($locale) {
                $client = $order->client;

                return [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'client' => [
                        'id' => $client?->id,
                        'name' => $client
                            ? ($client->getTranslation('full_name', $locale) ?: ($client->full_name ?? 'Customer'))
                            : 'Customer',
                        'image' => $client ? $this->uploadFilesService->getFullUrl($client->image) : null,
                    ],
                    'rating' => (int) $order->rating,
                    'review' => $order->review,
                    'created_at' => $order->updated_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Add branch
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required_without_all:name.ar,name.en'],
            'name.ar' => ['required_without:name', 'string', 'max:255'],
            'name.en' => ['required_without:name', 'string', 'max:255'],
            'phone_number' => ['required', 'string'],
            'land_phone' => ['nullable', 'string'],
            'description' => ['nullable'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'address_text' => ['nullable', 'string'],
            'national_address' => ['nullable', 'string', 'max:500'],
            'location_gps' => ['required', 'array'],
            'location_gps.lat' => ['required', 'numeric'],
            'location_gps.long' => ['required', 'numeric'],
            'image_cover' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'image_logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],

        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Ensure branch location is inside a defined zone
        $lat = (float) $request->location_gps['lat'];
        $long = (float) $request->location_gps['long'];
        $zone = Zone::findZoneByCoordinates($lat, $long);
        if (! $zone) {
            return validationErrorResponse([
                'location_gps' => [__('branch.location_outside_zones')],
            ], __('branch.cannot_add_branch_outside_zone'), 422);
        }

        $coverPath = null;
        if ($request->hasFile('image_cover')) {
            $coverPath = $this->uploadFilesService->uploadImage($request->file('image_cover'), 'branches/covers');
        }

        $logoPath = null;
        if ($request->hasFile('image_logo')) {
            $logoPath = $this->uploadFilesService->uploadImage($request->file('image_logo'), 'branches/logos');
        }

        // Handle translatable fields
        $name = $this->normalizeTranslatableField($request->name);
        $description = $request->has('description') ? $this->normalizeTranslatableField($request->description) : null;
        $addressValue = $request->input('address_text', $request->input('location_text'));
        $locationText = $addressValue ? $this->normalizeTranslatableField($addressValue) : ['ar' => '', 'en' => ''];

        $pickupOptions = is_array($request->input('pickup_options')) ? $request->input('pickup_options') : [];
        $branch = Branch::create([
            'vendor_id' => $vendorId,
            'name' => $name,
            'phone_number' => $request->phone_number,
            'land_phone' => $request->land_phone,
            'location' => $locationText,
            'national_address' => $request->national_address,
            'description' => $description,
            'store_front' => $coverPath,
            'logo' => $logoPath,
            'latitude' => $request->location_gps['lat'],
            'longitude' => $request->location_gps['long'],
            'zone_id' => $zone->id,
            'home_pickup' => filter_var($pickupOptions['Home_Pickup'] ?? $request->input('Home_Pickup', false), FILTER_VALIDATE_BOOLEAN),
            'self_dropoff' => filter_var($pickupOptions['Self_Dropoff'] ?? $request->input('Self_Dropoff', false), FILTER_VALIDATE_BOOLEAN),
            'home_delivery' => filter_var($pickupOptions['Home_Delivery'] ?? $request->input('Home_Delivery', false), FILTER_VALIDATE_BOOLEAN),
            'self_pickup' => filter_var($pickupOptions['Self_Pickup'] ?? $request->input('Self_Pickup', false), FILTER_VALIDATE_BOOLEAN),
            'is_active' => true,
        ]);

        // Sync branch pieces
        if ($request->has('branch_pieces')) {
            $piecesData = [];
            foreach ($request->branch_pieces as $piece) {
                // Verify piece belongs to vendor
                $pieceModel = Piece::where('id', $piece['piece_id'])
                    ->where('vendor_id', $vendorId)
                    ->first();

                if ($pieceModel) {
                    $piecesData[$piece['piece_id']] = [];
                }
            }
            if (! empty($piecesData)) {
                $branch->pieces()->sync($piecesData);
                // cache invalidation
                flushCacheTags(["branch_{$branch->id}", 'pieces', 'branches']);
            }
        }

        // Sync branch services
        if ($request->has('branch_services')) {
            $servicesData = [];
            foreach ($request->branch_services as $service) {
                // Verify service exists
                $serviceModel = Service::where('id', $service['service_id'])
                    ->first();

                if ($serviceModel) {
                    $pivotData = [
                        'price' => $service['price'] ?? null,
                    ];

                    // Handle custom icon_id for this branch-service
                    if (isset($service['icon_id'])) {
                        $pivotData['icon_id'] = $service['icon_id'];
                    }

                    // Handle custom description for this branch-service
                    if (isset($service['description'])) {
                        $pivotData['description'] = json_encode($service['description']);
                    }

                    $servicesData[$service['service_id']] = $pivotData;
                }
            }
            if (! empty($servicesData)) {
                $branch->services()->sync($servicesData);
                // cache invalidation
                flushCacheTags(["branch_{$branch->id}", 'services', 'branches']);
            }
        }

        // Reload branch with relationships
        $branch->refresh();
        $branch->load('zone');

        $branchInfo = [
            'address' => [
                'ar' => $branch->getTranslation('location', 'ar') ?? '',
                'en' => $branch->getTranslation('location', 'en') ?? '',
            ],
            'address_text' => $branch->getTranslation('location', app()->getLocale()) ?? '',
            'Phone_number' => $branch->phone_number,
            'land_phone' => $branch->land_phone,
            'location_gps' => [
                'lat' => (float) $branch->latitude,
                'long' => (float) $branch->longitude,
            ],
            'zone' => $this->formatZoneForResponse($branch->zone),
            'National_address' => $branch->national_address,
            'rating' => (float) ($branch->rating ?? 0),
            'rate_count' => (int) ($branch->rate_count ?? 0),
            'description' => [
                'ar' => $branch->getTranslation('description', 'ar'),
                'en' => $branch->getTranslation('description', 'en'),
            ],
            'is_active' => (bool) $branch->is_active,
        ];
        $branchInfo['home_pickup'] = (bool) $branch->home_pickup;
        $branchInfo['self_dropoff'] = (bool) $branch->self_dropoff;
        $branchInfo['home_delivery'] = (bool) $branch->home_delivery;
        $branchInfo['self_pickup'] = (bool) $branch->self_pickup;

        return successResponse([
            'branch_id' => $branch->id,
            'vendor_id' => $branch->vendor_id,
            'name' => [
                'ar' => $branch->getTranslation('name', 'ar'),
                'en' => $branch->getTranslation('name', 'en'),
            ],
            'image_cover' => $this->uploadFilesService->getFullUrl($branch->store_front),
            'image_logo' => $this->uploadFilesService->getFullUrl($branch->logo),
            'branch_info' => $branchInfo,
        ], __('branch.branch_added'), 201);
    }

    /**
     * Update branch
     */
    public function update(Request $request, $branchId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes'],
            'name.ar' => ['required_with:name', 'string', 'max:255'],
            'name.en' => ['required_with:name', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'string'],
            'land_phone' => ['nullable', 'string'],
            'description' => ['nullable'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'address_text' => ['nullable', 'string'],
            'national_address' => ['nullable', 'string', 'max:500'],
            'location_gps' => ['sometimes', 'array'],
            'location_gps.lat' => ['required_with:location_gps', 'numeric'],
            'location_gps.long' => ['required_with:location_gps', 'numeric'],
            'image_cover' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'image_logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        $data = [];

        if ($request->has('name')) {
            $data['name'] = $this->normalizeTranslatableField($request->name);
        }

        if ($request->has('phone_number')) {
            $data['phone_number'] = $request->phone_number;
        }

        if ($request->has('land_phone')) {
            $data['land_phone'] = $request->land_phone;
        }

        if ($request->has('description')) {
            $data['description'] = $this->normalizeTranslatableField($request->description);
        }

        if ($request->has('location_text') || $request->has('address_text')) {
            $data['location'] = $this->normalizeTranslatableField(
                $request->input('address_text', $request->input('location_text'))
            );
        }

        if ($request->has('national_address')) {
            $data['national_address'] = $request->national_address;
        }

        if ($request->has('location_gps')) {
            $lat = (float) $request->location_gps['lat'];
            $long = (float) $request->location_gps['long'];
            $zone = Zone::findZoneByCoordinates($lat, $long);
            if (! $zone) {
                return validationErrorResponse([
                    'location_gps' => [__('branch.location_outside_zones')],
                ], __('branch.cannot_add_branch_outside_zone'), 422);
            }
            $data['latitude'] = $lat;
            $data['longitude'] = $long;
            $data['zone_id'] = $zone->id;
        }

        if ($request->has('pickup_options') || $request->has('Home_Pickup') || $request->has('Self_Dropoff') || $request->has('Home_Delivery') || $request->has('Self_Pickup')) {
            $pickupOptions = is_array($request->input('pickup_options')) ? $request->input('pickup_options') : [];
            $data['home_pickup'] = filter_var($pickupOptions['Home_Pickup'] ?? $request->input('Home_Pickup', false), FILTER_VALIDATE_BOOLEAN);
            $data['self_dropoff'] = filter_var($pickupOptions['Self_Dropoff'] ?? $request->input('Self_Dropoff', false), FILTER_VALIDATE_BOOLEAN);
            $data['home_delivery'] = filter_var($pickupOptions['Home_Delivery'] ?? $request->input('Home_Delivery', false), FILTER_VALIDATE_BOOLEAN);
            $data['self_pickup'] = filter_var($pickupOptions['Self_Pickup'] ?? $request->input('Self_Pickup', false), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->is_active;
        }

        if ($request->hasFile('image_cover')) {
            $data['store_front'] = $this->uploadFilesService->uploadImage(
                $request->file('image_cover'),
                'branches/covers',
                $branch->store_front
            );
        }

        if ($request->hasFile('image_logo')) {
            $data['logo'] = $this->uploadFilesService->uploadImage(
                $request->file('image_logo'),
                'branches/logos',
                $branch->logo
            );
        }

        if (! empty($data)) {
            $branch->update($data);
        }

        $branch->refresh();
        $branch->load('zone');

        $branchInfo = [
            'address' => [
                'ar' => $branch->getTranslation('location', 'ar') ?? '',
                'en' => $branch->getTranslation('location', 'en') ?? '',
            ],
            'address_text' => $branch->getTranslation('location', app()->getLocale()) ?? '',
            'Phone_number' => $branch->phone_number,
            'land_phone' => $branch->land_phone,
            'location_gps' => [
                'lat' => (float) $branch->latitude,
                'long' => (float) $branch->longitude,
            ],
            'zone' => $this->formatZoneForResponse($branch->zone),
            'National_address' => $branch->national_address,
            'rating' => (float) ($branch->rating ?? 0),
            'rate_count' => (int) ($branch->rate_count ?? 0),
            'description' => [
                'ar' => $branch->getTranslation('description', 'ar'),
                'en' => $branch->getTranslation('description', 'en'),
            ],
            'is_active' => (bool) $branch->is_active,
        ];
        $branchInfo['home_pickup'] = (bool) $branch->home_pickup;
        $branchInfo['self_dropoff'] = (bool) $branch->self_dropoff;
        $branchInfo['home_delivery'] = (bool) $branch->home_delivery;
        $branchInfo['self_pickup'] = (bool) $branch->self_pickup;

        return successResponse([
            'branch_id' => $branch->id,
            'vendor_id' => $branch->vendor_id,
            'name' => [
                'ar' => $branch->getTranslation('name', 'ar'),
                'en' => $branch->getTranslation('name', 'en'),
            ],
            'image_cover' => $this->uploadFilesService->getFullUrl($branch->store_front),
            'image_logo' => $this->uploadFilesService->getFullUrl($branch->logo),
            'branch_info' => $branchInfo,
        ], __('branch.branch_updated'));
    }

    /**
     * Get branch working hours
     */
    public function getWorkingHours(Request $request, $branchId): JsonResponse
    {
        $branch = $this->findVendorBranch($request, $branchId, ['workingHourShifts']);

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        return successResponse([
            'branch_id' => $branch->id,
            'working_hours' => $branch->getApiWorkingHours(),
        ], __('branch.working_hours_retrieved'));
    }

    /**
     * Create or update branch working hours (replaces full schedule)
     */
    public function saveWorkingHours(Request $request, $branchId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'working_hours' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $workingHoursErrors = $this->workingHoursService->validate($request->input('working_hours'));
        if ($workingHoursErrors !== []) {
            return validationErrorResponse($workingHoursErrors);
        }

        $branch = $this->findVendorBranch($request, $branchId);

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        $isNew = ! $branch->workingHourShifts()->exists();

        $this->workingHoursService->sync($branch, $request->input('working_hours'));
        $branch->load('workingHourShifts');

        return successResponse([
            'branch_id' => $branch->id,
            'working_hours' => $branch->getApiWorkingHours(),
        ], __($isNew ? 'branch.working_hours_added' : 'branch.working_hours_updated'), $isNew ? 201 : 200);
    }

    /**
     * Delete branch
     */
    public function destroy(Request $request, $branchId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        $branch->delete();

        return successResponse(null, __('branch.branch_deleted'));
    }

    /**
     * Get services for branch
     */
    public function getServices(Request $request, $branchId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        // Get services for this branch
        $services = $branch->services()
            ->get()
            ->map(function ($service) use ($branch) {
                $rating = Order::whereHas('items.service', function ($query) use ($service) {
                    $query->where('services.id', $service->id);
                })
                    ->whereNotNull('rating')
                    ->avg('rating') ?? 0;

                // Get description - prioritize pivot description over service description
                $description = null;
                if ($service->pivot->description) {
                    $description = is_string($service->pivot->description)
                        ? json_decode($service->pivot->description, true)
                        : $service->pivot->description;
                } elseif ($service->description) {
                    $description = is_array($service->description)
                        ? $service->description
                        : json_decode($service->description, true);
                }

                // Get branch-specific icon or use service default
                $iconPath = null;
                if ($service->pivot->icon_id) {
                    $icon = \Modules\Admin\Models\Icon::find($service->pivot->icon_id);
                    $iconPath = $icon ? $icon->full_path : null;
                }
                if (! $iconPath && $service->iconRelation) {
                    $iconPath = $service->iconRelation->full_path;
                }

                return array_merge([
                    'service_id' => $service->id,
                    'name' => $service->getTranslation('service_name', app()->getLocale()),
                    'description' => $description,
                    'rating' => round((float) $rating, 2),
                    'icon' => $iconPath,
                    'icon_id' => $service->pivot->icon_id,
                ], \App\Support\CatalogActivePresenter::service($service, (int) $branch->id, $service->pivot));
            });

        return successResponse($services, __('branch.branch_services_retrieved'));
    }

    /**
     * Add service to branch
     * POST /api/v1/vendor/branches/{branchId}/services
     */
    public function addService(Request $request, $branchId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'price' => ['prohibited'],
            'icon_id' => ['nullable', 'integer', 'exists:icons,id'],
            'name' => ['nullable', 'array'],
            'name.ar' => ['nullable', 'string'],
            'name.en' => ['nullable', 'string'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Check if branch belongs to vendor
        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        // Check if service exists
        $service = Service::find($request->service_id);

        if (! $service) {
            return notFoundResponse(__('branch.service_not_found'));
        }

        if (! $branch->vendor->catalogServices()->where('services.id', $service->id)->exists()) {
            return validationErrorResponse([
                'service_id' => [__('service.service_not_in_vendor_catalog')],
            ]);
        }

        // Check if service already exists in branch
        $existingService = $branch->services()
            ->where('services.id', $request->service_id)
            ->first();

        // Prepare pivot data (link only — prices live on service_piece per piece)
        $pivotData = [];

        // Handle custom icon_id for this branch-service
        if ($request->has('icon_id')) {
            $pivotData['icon_id'] = $request->icon_id;
        }

        // Handle custom name for this branch-service
        if ($request->has('name')) {
            $pivotData['name'] = json_encode($request->name);
        }

        // Handle custom description for this branch-service
        if ($request->has('description')) {
            $pivotData['description'] = json_encode($request->description);
        }

        // Attach or update service to branch
        $branch->services()->syncWithoutDetaching([
            $request->service_id => $pivotData,
        ]);

        // cache invalidation: branch service added
        flushCacheTags(["branch_{$branchId}", 'services', 'branches']);

        // Get the updated service data
        $branchService = $branch->services()
            ->where('services.id', $request->service_id)
            ->first();

        $message = $existingService
            ? __('branch.service_updated_in_branch')
            : __('branch.service_added_to_branch');

        // Get icon path - prioritize pivot icon_id over service icon_id
        $iconPath = null;
        if ($branchService->pivot->icon_id) {
            $icon = \Modules\Admin\Models\Icon::find($branchService->pivot->icon_id);
            $iconPath = $icon ? $icon->full_path : null;
        }
        if (! $iconPath && $service->iconRelation) {
            $iconPath = $service->iconRelation->full_path;
        }

        // Get description - prioritize pivot description over service description
        $description = null;
        if ($branchService->pivot->description) {
            $description = is_string($branchService->pivot->description)
                ? json_decode($branchService->pivot->description, true)
                : $branchService->pivot->description;
        } elseif ($service->description) {
            $description = is_array($service->description)
                ? $service->description
                : json_decode($service->description, true);
        }

        return successResponse([
            'branch_id' => $branch->id,
            'branch_name' => $branch->getTranslation('name', app()->getLocale()),
            'service_id' => $service->id,
            'service_name' => $service->getTranslation('service_name', app()->getLocale()),
            'icon_id' => $branchService->pivot->icon_id ?? null,
            'icon' => $iconPath,
            'description' => $description,
        ], $message, $existingService ? 200 : 201);
    }

    /**
     * Get drivers for branch
     */
    public function getDrivers(Request $request, $branchId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        // Get only drivers assigned to this branch
        $drivers = $branch->drivers()
            ->get()
            ->map(function ($driver) {
                return [
                    'driver_id' => $driver->id,
                    'name' => $driver->full_name,
                    'phone_number' => $driver->phone,
                    'rating' => (float) ($driver->rating ?? 0),
                    'image' => $driver->image,
                ];
            });

        return successResponse($drivers, __('branch.branch_drivers_retrieved'));
    }

    /**
     * Get pieces for branch with additional services and branch-specific prices
     */
    public function getPieces(Request $request, $branchId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        // Get only pieces that are assigned to this branch
        $branchServiceIds = $branch->services()->pluck('services.id');

        $pieces = $branch->pieces()
            ->with(['services' => function ($q) use ($branchServiceIds) {
                $q->whereIn('services.id', $branchServiceIds)
                    ->where('services.is_active', true);
            }])
            ->get()
            ->map(function ($piece) use ($branchId) {
                $locale = app()->getLocale();
                $additionalServices = \Modules\Piece\Support\PiecePricingFormatter::additionalServicesForPiece(
                    $piece,
                    $branchId,
                    $locale
                );

                $services = collect(\Modules\Piece\Support\PiecePricingFormatter::mapServicesWithPrices(
                    $piece->services,
                    $piece,
                    $branchId,
                    app()->getLocale()
                ));

                $pieceFields = \Modules\Piece\Support\PieceBranchOffering::branchApiFields(
                    $piece,
                    (int) $branchId,
                    app()->getLocale()
                );

                return array_merge($pieceFields, [
                    'piece_id' => $piece->id,
                    'icon' => $piece->iconRelation?->full_path,
                    'rating' => 0,
                    'services' => $services,
                    'additional_services' => $additionalServices,
                ]);
            });

        return successResponse($pieces, __('branch.branch_pieces_retrieved'));
    }

    /**
     * Update branch-specific service pricing
     * PUT /api/vendor/branches/{branchId}/services/{serviceId}/pricing
     */
    public function updateServicePricing(Request $request, $branchId, $serviceId): JsonResponse
    {
        return validationErrorResponse([
            'price' => [__('service.service_price_on_piece_only')],
        ]);
    }

    /**
     * Sync services to branch with custom data (price, icon)
     * POST /api/v1/vendor/branches/{branchId}/sync-services
     */
    public function syncServices(Request $request, $branchId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'services' => ['required', 'array', 'min:1'],
            'services.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'services.*.price' => ['prohibited'],
            'services.*.icon_id' => ['nullable', 'integer', 'exists:icons,id'],
            'services.*.name' => ['nullable', 'array'],
            'services.*.name.ar' => ['nullable', 'string'],
            'services.*.name.en' => ['nullable', 'string'],
            'services.*.description' => ['nullable', 'array'],
            'services.*.description.ar' => ['nullable', 'string'],
            'services.*.description.en' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        $syncData = [];
        $syncedServices = [];
        $errors = [];

        foreach ($request->services as $index => $serviceData) {
            $serviceId = $serviceData['service_id'];

            // Check if service exists
            $service = Service::find($serviceId);

            if (! $service) {
                $errors[] = __('service.service_not_found')." (ID: {$serviceId})";

                continue;
            }

            $pivotData = [];

            // Handle custom icon_id for this branch-service
            if (isset($serviceData['icon_id'])) {
                $pivotData['icon_id'] = $serviceData['icon_id'];
            }

            // Handle custom name for this branch-service
            if (isset($serviceData['name'])) {
                $pivotData['name'] = json_encode($serviceData['name']);
            }

            // Handle custom description for this branch-service
            if (isset($serviceData['description'])) {
                $pivotData['description'] = json_encode($serviceData['description']);
            }

            $syncData[$serviceId] = $pivotData;

            $syncedServices[] = [
                'service_id' => $service->id,
                'service_name' => $service->getTranslation('service_name', app()->getLocale()),
            ];
        }

        // Sync services to branch (without detaching existing)
        if (! empty($syncData)) {
            $branch->services()->syncWithoutDetaching($syncData);
            // cache invalidation: branch services sync
            flushCacheTags(["branch_{$branchId}", 'services', 'branches']);
        }

        $response = [
            'branch_id' => $branch->id,
            'branch_name' => $branch->getTranslation('name', app()->getLocale()),
            'synced_services_count' => count($syncedServices),
            'synced_services' => $syncedServices,
        ];

        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        $message = count($syncedServices) > 0
            ? __('branch.services_synced_successfully')
            : __('branch.no_services_synced');

        return successResponse($response, $message, 201);
    }

    /**
     * Sync vendor pieces to specific branch
     * POST /api/vendor/branches/{branchId}/sync-pieces
     */
    public function syncPieces(Request $request, $branchId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found'));
        }

        // Get all vendor pieces
        $pieces = Piece::where('vendor_id', $vendorId)->pluck('id')->toArray();

        // Sync pieces to branch (without detaching existing)
        $syncData = [];
        foreach ($pieces as $pieceId) {
            $syncData[$pieceId] = [];
        }

        $branch->pieces()->syncWithoutDetaching($syncData);

        // cache invalidation: branch pieces sync
        flushCacheTags(["branch_{$branchId}", 'pieces', 'branches']);

        return successResponse([
            'branch_id' => $branch->id,
            'synced_pieces_count' => count($pieces),
        ], 'Pieces synced to branch successfully');
    }

    /**
     * Remove service from branch
     * DELETE /api/v1/vendor/branches/{branchId}/services/{serviceId}
     */
    public function removeService(Request $request, $branchId, $serviceId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // Check if branch belongs to vendor
        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        // Check if service exists in branch
        $branchService = $branch->services()
            ->where('services.id', $serviceId)
            ->first();

        if (! $branchService) {
            return notFoundResponse(__('branch.service_not_found_in_branch'));
        }

        // Remove service from branch
        $branch->services()->detach($serviceId);

        // cache invalidation: branch service removed
        flushCacheTags(["branch_{$branchId}", 'services', 'branches']);

        return successResponse([
            'branch_id' => $branch->id,
            'service_id' => $serviceId,
        ], __('branch.service_removed_from_branch'));
    }

    /**
     * Add or update branch offering for a catalog additional service (no catalog edit).
     * Piece links are optional — omit piece_id for branch-only; link later via
     * POST /vendor/pieces/{pieceId}/additional-services or assign-service-additions.
     *
     * POST /api/v1/vendor/branches/{branchId}/additional-services
     */
    public function addAdditionalService(Request $request, $branchId): JsonResponse
    {
        $branchId = (int) $branchId;

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $validator = Validator::make($request->all(), [
            'service_addition_id' => [
                'required',
                'integer',
                Rule::exists('service_additions', 'id')->where('vendor_id', $vendorId),
            ],
            'piece_id' => ['nullable', 'integer', 'exists:pieces,id'],
            'assign_to_all_pieces' => ['nullable', 'boolean'],
            'price' => ['required', 'numeric', 'min:0'],
            'name' => ['nullable', 'array'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'icon_id' => ['nullable', 'integer', 'exists:icons,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        $serviceAddition = ServiceAddition::where('id', $request->service_addition_id)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $serviceAddition) {
            return notFoundResponse('Additional service not found or not yours');
        }

        $branchPrice = (float) $request->price;
        $locale = app()->getLocale();

        // Branch offering only — do not modify the catalog service_additions row.
        ServiceAdditionBranchOffering::upsert($branchId, $serviceAddition, [
            'price' => $branchPrice,
            'name' => $request->has('name') ? $request->name : null,
            'icon_id' => $request->icon_id,
        ]);

        $displayName = $serviceAddition->getDisplayNameAtBranch($branchId, $locale);
        $assignedCount = 0;

        if ($request->filled('piece_id')) {
            $piece = Piece::where('id', $request->piece_id)
                ->where('vendor_id', $vendorId)
                ->first();

            if (! $piece) {
                return notFoundResponse(__('piece.piece_not_found'));
            }

            if (! $branch->pieces()->where('pieces.id', $piece->id)->exists()) {
                return validationErrorResponse([
                    'piece_id' => [__('piece.piece_not_available_at_branch')],
                ]);
            }

            ServiceAdditionBranchOffering::linkToPiece(
                $branchId,
                $piece->id,
                $serviceAddition,
                $branchPrice
            );

            $assignedCount = 1;
        } elseif ($request->boolean('assign_to_all_pieces')) {
            $pieces = $branch->pieces;
            foreach ($pieces as $piece) {
                ServiceAdditionBranchOffering::linkToPiece(
                    $branchId,
                    $piece->id,
                    $serviceAddition,
                    $branchPrice
                );
            }
            $assignedCount = $pieces->count();
        }

        flushCacheTags(["branch_{$branchId}", 'services', 'branches']);

        return successResponse([
            'branch_id' => $branch->id,
            'service_addition_id' => $serviceAddition->id,
            'service_addition_name' => $displayName,
            'catalog_service_addition_id' => $serviceAddition->id,
            'price' => $branchPrice,
            'branch_offering_only' => $assignedCount === 0,
            'assigned_to_pieces_count' => $assignedCount,
        ], 'Additional service assigned successfully', 201);
    }

    /**
     * Remove additional service from a branch (catalog row is kept).
     * DELETE /api/v1/vendor/branches/{branchId}/additional-services/{serviceAdditionId}
     * Optional query: piece_id — remove from one piece at that branch only.
     */
    public function removeAdditionalService(Request $request, $branchId, $serviceAdditionId): JsonResponse
    {
        $branchId = (int) $branchId;
        $serviceAdditionId = (int) $serviceAdditionId;

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $validator = Validator::make($request->all(), [
            'piece_id' => ['nullable', 'integer', 'exists:pieces,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        $serviceAddition = ServiceAddition::where('id', $serviceAdditionId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $serviceAddition) {
            return notFoundResponse(__('service.service_addition_not_found'));
        }

        if ($request->filled('piece_id')) {
            $pieceId = (int) $request->piece_id;

            if (! $branch->pieces()->where('pieces.id', $pieceId)->exists()) {
                return validationErrorResponse([
                    'piece_id' => [__('piece.piece_not_available_at_branch')],
                ]);
            }

            $removed = \Modules\Service\Support\ServiceAdditionBranchOffering::removeFromPieceAtBranch(
                $branchId,
                $pieceId,
                $serviceAdditionId
            );

            if (! $removed) {
                return notFoundResponse(__('service.service_addition_not_on_branch'));
            }

            flushCacheTags(["branch_{$branchId}", 'services', 'pieces', 'branches']);

            return successResponse([
                'branch_id' => $branchId,
                'service_addition_id' => $serviceAdditionId,
                'piece_id' => $pieceId,
                'removed_from' => 'piece_at_branch',
                'catalog_preserved' => true,
            ], __('service.service_addition_removed_from_piece'));
        }

        $pieceLinksRemoved = \Modules\Service\Support\ServiceAdditionBranchOffering::removeFromBranch(
            $branchId,
            $serviceAdditionId
        );

        flushCacheTags(["branch_{$branchId}", 'services', 'pieces', 'branches']);

        return successResponse([
            'branch_id' => $branchId,
            'service_addition_id' => $serviceAdditionId,
            'piece_links_removed' => $pieceLinksRemoved,
            'catalog_preserved' => true,
        ], __('service.service_addition_removed_from_branch'));
    }

    /**
     * Find a branch that belongs to the authenticated vendor.
     */
    private function findVendorBranch(Request $request, $branchId, array $with = []): ?Branch
    {
        $employee = $request->user();
        $query = Branch::where('id', $branchId)
            ->where('vendor_id', $employee->vendor_id);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Normalize translatable field to array format
     *
     * @param  mixed  $value
     */
    private function normalizeTranslatableField($value): array
    {
        if (\is_string($value)) {
            return ['ar' => $value, 'en' => $value];
        }

        if (\is_array($value)) {
            return [
                'ar' => $value['ar'] ?? $value['en'] ?? '',
                'en' => $value['en'] ?? $value['ar'] ?? '',
            ];
        }

        return ['ar' => '', 'en' => ''];
    }
}
