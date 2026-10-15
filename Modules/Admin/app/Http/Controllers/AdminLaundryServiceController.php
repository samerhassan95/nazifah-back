<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use App\Support\CatalogActivePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Icon;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Service\Models\Service;
use Modules\Service\Support\ServiceVendorOffering;

class AdminLaundryServiceController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get services for a laundry
     * GET /laundries/services
     *
     * - ?vendor_id=X            → the laundry's services (per-laundry name/description/icon/active flag)
     * - ?vendor_id=X&scope=available → catalog services the laundry does not have yet
     *                                  (services from Categories & Services plus any created from the laundry screens)
     * - ?branch_id=X            → services linked to that branch
     * - no filters              → the full services catalog
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');
        $branchId = $request->input('branch_id');
        $scope    = $request->input('scope');

        $query = Service::with(['category', 'categories']);

        if ($request->has('is_active')) {
            $query->where('services.is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        } else {
            // Services stopped by admin must never show up in laundries, branches, or available popup
            $query->where('services.is_active', true);
        }

        $referer = (string) $request->header('referer', '');
        $isBranchContext = $branchId
            || str_contains($referer, '/branches/')
            || str_contains($referer, '/branch/')
            || $request->boolean('vendor_is_active')
            || $request->boolean('active_only')
            || $request->input('for') === 'branch';

        if ($scope === 'available' && $vendorId) {
            $query->whereDoesntHave('vendors', fn ($v) => $v->where('vendors.id', $vendorId))
                ->orderBy('services.id');
        } elseif ($branchId) {
            $branch = Branch::find($branchId);
            $query->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId)
                  ->where('branch_service.is_active', true);
            });
            if ($branch && $branch->vendor_id) {
                $vId = $branch->vendor_id;
                $query->whereHas('vendors', function ($v) use ($vId) {
                    $v->where('vendors.id', $vId)
                      ->where('vendor_service.is_active', true);
                });
            }
        } elseif ($vendorId) {
            $query->whereHas('vendors', function ($v) use ($vendorId, $isBranchContext) {
                $v->where('vendors.id', $vendorId);
                if ($isBranchContext) {
                    // For branches, only show services that are active in the laundry
                    $v->where('vendor_service.is_active', true);
                }
            });
        }

        $services = $query->paginate($request->input('per_page', 15));

        $locale          = app()->getLocale();
        $vendorBranchIds = $vendorId ? Branch::where('vendor_id', $vendorId)->pluck('id')->all() : [];

        // Pre-compute ratings for all services in one query to avoid N+1 avg() calls.
        $ratingsMap = [];
        if ($vendorId && ! empty($vendorBranchIds)) {
            $serviceIds = $services->getCollection()->pluck('id')->all();
            $ratingsMap = Order::query()
                ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('orders.branch_id', $vendorBranchIds)
                ->whereIn('order_items.service_id', $serviceIds)
                ->whereNotNull('orders.rating')
                ->groupBy('order_items.service_id')
                ->selectRaw('order_items.service_id, AVG(orders.rating) as avg_rating')
                ->pluck('avg_rating', 'service_id')
                ->all();
        }

        $servicesData = $services->getCollection()->map(function ($service) use ($vendorId, $locale, $vendorBranchIds, $scope, $ratingsMap) {
            if ($vendorId) {
                return $this->formatVendorService($service, (int) $vendorId, $locale, $vendorBranchIds, $scope === 'available', $ratingsMap);
            }

            $categoryIds = $service->relationLoaded('categories')
                ? $service->categories->pluck('id')->values()->all()
                : ($service->category_id ? [(int) $service->category_id] : []);

            return [
                'id'                  => $service->id,
                'category_id'         => $service->category_id,
                'category_ids'        => $categoryIds,
                'icon'                => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                'Service_name'        => $service->getTranslation('service_name', $locale),
                'Service_description' => $service->getTranslation('description', $locale),
                'Category'            => $service->category ? $service->category->getTranslation('name', $locale) : null,
                'Categories'          => $service->relationLoaded('categories')
                    ? $service->categories->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->getTranslation('name', $locale),
                    ])->values()->all()
                    : ($service->category ? [['id' => $service->category->id, 'name' => $service->category->getTranslation('name', $locale)]] : []),
                'is_active'           => (bool) $service->is_active,
            ];
        });

        $services->setCollection($servicesData);

        return successResponse($services, __('vendor.services_retrieved_successfully'));
    }


    /**
     * Get single service
     * GET /laundries/services/:id
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $service = Service::with(['category', 'categories', 'pieces'])->find($id);

        if (! $service) {
            return notFoundResponse(__('service.not_found'));
        }

        $locale = app()->getLocale();

        $categoryIds = $service->relationLoaded('categories')
            ? $service->categories->pluck('id')->values()->all()
            : ($service->category_id ? [(int) $service->category_id] : []);

        $serviceData = [
            'id' => $service->id,
            'category_id' => $service->category_id,
            'category_ids' => $categoryIds,
            'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
            'Service_name' => [
                'ar' => $service->getTranslation('service_name', 'ar'),
                'en' => $service->getTranslation('service_name', 'en'),
            ],
            'Service_description' => [
                'ar' => $service->getTranslation('description', 'ar'),
                'en' => $service->getTranslation('description', 'en'),
            ],
            'Category' => $service->category ? [
                'ar' => $service->category->getTranslation('name', 'ar'),
                'en' => $service->category->getTranslation('name', 'en'),
            ] : null,
            'Categories' => $service->relationLoaded('categories')
                ? $service->categories->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => [
                        'ar' => $c->getTranslation('name', 'ar'),
                        'en' => $c->getTranslation('name', 'en'),
                    ],
                ])->values()->all()
                : ($service->category ? [[
                    'id' => $service->category->id,
                    'name' => [
                        'ar' => $service->category->getTranslation('name', 'ar'),
                        'en' => $service->category->getTranslation('name', 'en'),
                    ],
                ]] : []),
            'is_active' => (bool) $service->is_active,
        ];

        return successResponse($serviceData, __('vendor.service_retrieved_successfully'));
    }

    /**
     * Create service
     * POST /laundries/services
     *
     * The service is always created in the shared catalog (so it also shows up under
     * Categories & Services). When vendor_id is sent it is added to that laundry as well.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'nullable|exists:vendors,id',
            'icon_id' => 'nullable|exists:icons,id',
            'service_name' => 'required|array',
            'service_name.ar' => 'required|string|max:255',
            'service_name.en' => 'required|string|max:255',
            'service_description' => 'nullable|array',
            'service_description.ar' => 'nullable|string',
            'service_description.en' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $categoryIds = [];
        if (! empty($validated['category_ids'])) {
            $categoryIds = array_map('intval', $validated['category_ids']);
        } elseif (! empty($validated['category_id'])) {
            $categoryIds = [(int) $validated['category_id']];
        }

        $primaryCategoryId = ! empty($categoryIds) ? $categoryIds[0] : ($validated['category_id'] ?? null);

        $serviceData = [
            'service_name' => $validated['service_name'],
            'description' => $validated['service_description'] ?? null,
            'category_id' => $primaryCategoryId,
            'icon_id' => $validated['icon_id'] ?? null,
            'is_active' => true,
        ];

        $service = Service::create($serviceData);

        if (! empty($categoryIds)) {
            $service->categories()->sync($categoryIds);
        }

        if (! empty($validated['vendor_id'])) {
            ServiceVendorOffering::upsert((int) $validated['vendor_id'], $service, ['is_active' => true]);
        }

        $locale = app()->getLocale();

        return successResponse(
            $this->formatService($service->load(['category', 'categories']), $locale),
            __('service.created_successfully'),
            201
        );
    }

    /**
     * Add an existing catalog service to a laundry
     * POST /laundries/services/attach
     */
    public function attachToVendor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'service_id' => 'required|exists:services,id',
        ]);

        $vendorId = (int) $validated['vendor_id'];
        $service = Service::with('category')->find($validated['service_id']);

        if (! $service->is_active) {
            return errorResponse(__('service.not_found'), null, 422);
        }

        $alreadyAdded = ServiceVendorOffering::find($vendorId, $service->id) !== null;

        if (! $alreadyAdded) {
            ServiceVendorOffering::upsert($vendorId, $service, ['is_active' => true]);
        }

        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id')->all();

        return successResponse(
            $this->formatVendorService($service, $vendorId, app()->getLocale(), $branchIds),
            __('service.created_successfully'),
            $alreadyAdded ? 200 : 201
        );
    }

    /**
     * Turn a service on/off for one laundry
     * POST /laundries/services/:id/toggle-status  (body: vendor_id)
     */
    public function toggleForVendor(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
        ]);

        $vendorId = (int) $validated['vendor_id'];
        $service = Service::with('category')->find($id);

        if (! $service || ServiceVendorOffering::toggleActive($vendorId, $id) === null) {
            return notFoundResponse(__('service.not_found'));
        }

        // Pass empty branchIds to skip the heavy Order avg(rating) query —
        // rating is not needed for a toggle response and causes noticeable latency.
        return successResponse(
            $this->formatVendorService($service, $vendorId, app()->getLocale(), []),
            __('service.updated_successfully')
        );
    }

    /**
     * Update service
     * PUT /laundries/services/:id
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $service = Service::find($id);

        if (! $service) {
            return notFoundResponse(__('service.not_found'));
        }

        $validated = $request->validate([
            'icon_id' => 'nullable|exists:icons,id',
            'service_name' => 'sometimes|array',
            'service_name.ar' => 'sometimes|string|max:255',
            'service_name.en' => 'sometimes|string|max:255',
            'service_description' => 'nullable|array',
            'service_description.ar' => 'nullable|string',
            'service_description.en' => 'nullable|string',
            'category_id' => 'sometimes|nullable|exists:categories,id',
            'category_ids' => 'sometimes|nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $serviceData = [];

        if (array_key_exists('category_ids', $validated) && is_array($validated['category_ids'])) {
            $catIds = array_map('intval', $validated['category_ids']);
            $service->categories()->sync($catIds);
            if (! empty($catIds)) {
                $serviceData['category_id'] = $catIds[0];
            }
        } elseif (array_key_exists('category_id', $validated)) {
            $serviceData['category_id'] = $validated['category_id'];
            if ($validated['category_id']) {
                $service->categories()->syncWithoutDetaching([(int) $validated['category_id']]);
            }
        }

        if (isset($validated['service_name'])) {
            $serviceData['service_name'] = $validated['service_name'];
        }

        if (isset($validated['service_description'])) {
            $serviceData['description'] = $validated['service_description'];
        }

        if ($request->has('icon_id')) {
            $serviceData['icon_id'] = $validated['icon_id'];
        }

        $service->update($serviceData);

        $locale = app()->getLocale();

        return successResponse(
            $this->formatService($service->fresh()->load(['category', 'categories']), $locale),
            __('service.updated_successfully')
        );
    }

    /**
     * Delete service from a laundry (detach from laundry and its branches)
     * DELETE /laundries/services/:id
     *
     * Removes the service from the laundry's catalog (vendor_service and branch_service)
     * without deleting it from the global catalog (services table), so it remains in
     * Categories & Services and can be re-selected from the "Add Service" popup.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $service = Service::find($id);

        if (! $service) {
            return notFoundResponse(__('service.not_found'));
        }

        $vendorId = $request->input('vendor_id') ?? $request->header('vendor-id');

        // If vendor_id is not directly in the request, try to extract it from the Referer URL
        // e.g. https://manager.nathefah.com/laundries/12/services or /laundries/12
        if (! $vendorId && $referer = $request->header('referer')) {
            if (preg_match('#/laundries/(\d+)#', $referer, $matches)) {
                $vendorId = (int) $matches[1];
            }
        }

        if ($vendorId) {
            $vendorId = (int) $vendorId;

            // 1. Remove from vendor_service
            DB::table('vendor_service')
                ->where('vendor_id', $vendorId)
                ->where('service_id', $id)
                ->delete();

            // 2. Remove from branch_service for all branches of this vendor
            $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id')->all();
            if (! empty($branchIds)) {
                DB::table('branch_service')
                    ->whereIn('branch_id', $branchIds)
                    ->where('service_id', $id)
                    ->delete();
            }

            // Invalidate caches
            flushCacheTags(['services', 'categories', 'branches']);

            return successResponse(null, __('service.deleted_successfully'));
        }

        // If no specific vendor was resolved, detach from all vendors in vendor_service
        // while strictly keeping the service intact in the global catalog.
        DB::table('vendor_service')->where('service_id', $id)->delete();
        DB::table('branch_service')->where('service_id', $id)->delete();

        flushCacheTags(['services', 'categories', 'branches']);

        return successResponse(null, __('service.deleted_successfully'));
    }

    /**
     * A service as one laundry sees it: the laundry's own name/description/icon when it
     * overrides them, otherwise the catalog values, plus its active flags and rating.
     */
    private function formatVendorService(Service $service, int $vendorId, string $locale, array $branchIds, bool $availableOnly = false, array $ratingsMap = []): array
    {
        $iconId   = ServiceVendorOffering::iconIdForVendor($vendorId, $service);
        $icon     = $iconId ? Icon::find($iconId) : null;
        $iconPath = $icon ? ($icon->full_path ?? $icon->path) : ($service->iconRelation?->full_path ?? $service->iconRelation?->path);

        // Use precomputed rating if available, otherwise query (fallback for single-service endpoints).
        if (array_key_exists($service->id, $ratingsMap)) {
            $rating = $ratingsMap[$service->id] ?? 0;
        } else {
            $rating = empty($branchIds) ? 0 : (Order::whereHas('items.service', fn ($q) => $q->where('services.id', $service->id))
                ->whereIn('branch_id', $branchIds)
                ->whereNotNull('rating')
                ->avg('rating') ?? 0);
        }

        $categoryIds = $service->relationLoaded('categories')
            ? $service->categories->pluck('id')->values()->all()
            : ($service->category_id ? [(int) $service->category_id] : []);

        return array_merge([
            'id'                  => $service->id,
            'category_id'         => $service->category_id,
            'category_ids'        => $categoryIds,
            'icon'                => $this->uploadFilesService->getFullUrl($iconPath),
            'Service_name'        => ServiceVendorOffering::displayNameForVendor($service, $vendorId, $locale),
            'Service_description' => ServiceVendorOffering::descriptionForVendor($service, $vendorId, $locale),
            'Category'            => $service->category ? $service->category->getTranslation('name', $locale) : null,
            'Categories'          => $service->relationLoaded('categories')
                ? $service->categories->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->getTranslation('name', $locale),
                ])->values()->all()
                : ($service->category ? [['id' => $service->category->id, 'name' => $service->category->getTranslation('name', $locale)]] : []),
            'rating'              => round((float) $rating, 2),
            'vendor_id'           => $vendorId,
            'in_vendor_catalog'   => ServiceVendorOffering::find($vendorId, $service->id) !== null,
            'source'              => $availableOnly ? 'available_for_vendor' : 'vendor_catalog',
        ], CatalogActivePresenter::service($service, null, null, $vendorId));
    }

    /**
     * Format service data for response
     */
    private function formatService($service, $locale = 'ar'): array
    {
        $categoryIds = $service->relationLoaded('categories')
            ? $service->categories->pluck('id')->values()->all()
            : ($service->category_id ? [(int) $service->category_id] : []);

        return [
            'id' => $service->id,
            'category_id' => $service->category_id,
            'category_ids' => $categoryIds,
            'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
            'Service_name' => [
                'ar' => $service->getTranslation('service_name', 'ar'),
                'en' => $service->getTranslation('service_name', 'en'),
            ],
            'Service_description' => [
                'ar' => $service->getTranslation('description', 'ar'),
                'en' => $service->getTranslation('description', 'en'),
            ],
            'Category' => $service->category ? [
                'ar' => $service->category->getTranslation('name', 'ar'),
                'en' => $service->category->getTranslation('name', 'en'),
            ] : null,
            'Categories' => $service->relationLoaded('categories')
                ? $service->categories->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => [
                        'ar' => $c->getTranslation('name', 'ar'),
                        'en' => $c->getTranslation('name', 'en'),
                    ],
                ])->values()->all()
                : ($service->category ? [[
                    'id' => $service->category->id,
                    'name' => [
                        'ar' => $service->category->getTranslation('name', 'ar'),
                        'en' => $service->category->getTranslation('name', 'en'),
                    ],
                ]] : []),
            'is_active' => (bool) $service->is_active,
        ];
    }
}
