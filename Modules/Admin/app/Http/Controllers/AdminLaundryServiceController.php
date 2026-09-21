<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use App\Support\CatalogActivePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $scope = $request->input('scope');

        $query = Service::with(['category']);

        if ($scope === 'available' && $vendorId) {
            $query->where('services.is_active', true)
                ->whereDoesntHave('vendors', fn ($v) => $v->where('vendors.id', $vendorId))
                ->orderBy('services.id');
        } elseif ($branchId) {
            $query->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId));
        } elseif ($vendorId) {
            $query->where(function ($q) use ($vendorId) {
                $q->whereHas('vendors', fn ($v) => $v->where('vendors.id', $vendorId))
                    ->orWhereHas('branches', fn ($b) => $b->where('branches.vendor_id', $vendorId))
                    ->orWhereHas('pieces', fn ($p) => $p->where('pieces.vendor_id', $vendorId));
            });
        }

        $services = $query->paginate($request->input('per_page', 15));

        $locale = app()->getLocale();
        $vendorBranchIds = $vendorId ? Branch::where('vendor_id', $vendorId)->pluck('id')->all() : [];

        $servicesData = $services->getCollection()->map(function ($service) use ($vendorId, $locale, $vendorBranchIds, $scope) {
            if ($vendorId) {
                return $this->formatVendorService($service, (int) $vendorId, $locale, $vendorBranchIds, $scope === 'available');
            }

            return [
                'id' => $service->id,
                'category_id' => $service->category_id,
                'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                'Service_name' => $service->getTranslation('service_name', $locale),
                'Service_description' => $service->getTranslation('description', $locale),
                'Category' => $service->category ? $service->category->getTranslation('name', $locale) : null,
                'is_active' => (bool) $service->is_active,
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
        $service = Service::with(['category', 'pieces'])->find($id);

        if (! $service) {
            return notFoundResponse(__('service.not_found'));
        }

        $locale = app()->getLocale();

        $serviceData = [
            'id' => $service->id,
            'category_id' => $service->category_id,
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
            'category_id' => 'required|exists:categories,id',
        ]);

        $serviceData = [
            'service_name' => $validated['service_name'],
            'description' => $validated['service_description'] ?? null,
            'category_id' => $validated['category_id'],
            'icon_id' => $validated['icon_id'] ?? null,
            'is_active' => true,
        ];

        $service = Service::create($serviceData);

        if (! empty($validated['vendor_id'])) {
            ServiceVendorOffering::upsert((int) $validated['vendor_id'], $service, ['is_active' => true]);
        }

        $locale = app()->getLocale();

        return successResponse(
            $this->formatService($service, $locale),
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

        $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id')->all();

        return successResponse(
            $this->formatVendorService($service, $vendorId, app()->getLocale(), $branchIds),
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
            'category_id' => 'sometimes|exists:categories,id',
        ]);

        $serviceData = [];

        if (isset($validated['service_name'])) {
            $serviceData['service_name'] = $validated['service_name'];
        }

        if (isset($validated['service_description'])) {
            $serviceData['description'] = $validated['service_description'];
        }

        if (isset($validated['category_id'])) {
            $serviceData['category_id'] = $validated['category_id'];
        }

        if ($request->has('icon_id')) {
            $serviceData['icon_id'] = $validated['icon_id'];
        }

        $service->update($serviceData);

        $locale = app()->getLocale();

        return successResponse(
            $this->formatService($service->fresh(), $locale),
            __('service.updated_successfully')
        );
    }

    /**
     * Delete service
     * DELETE /laundries/services/:id
     */
    public function destroy(int $id): JsonResponse
    {
        $service = Service::find($id);

        if (! $service) {
            return notFoundResponse(__('service.not_found'));
        }

        $service->delete();

        return successResponse(null, __('service.deleted_successfully'));
    }

    /**
     * A service as one laundry sees it: the laundry's own name/description/icon when it
     * overrides them, otherwise the catalog values, plus its active flags and rating.
     */
    private function formatVendorService(Service $service, int $vendorId, string $locale, array $branchIds, bool $availableOnly = false): array
    {
        $iconId = ServiceVendorOffering::iconIdForVendor($vendorId, $service);
        $icon = $iconId ? Icon::find($iconId) : null;
        $iconPath = $icon ? ($icon->full_path ?? $icon->path) : ($service->iconRelation?->full_path ?? $service->iconRelation?->path);

        $rating = empty($branchIds) ? 0 : (Order::whereHas('items.service', fn ($q) => $q->where('services.id', $service->id))
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('rating')
            ->avg('rating') ?? 0);

        return array_merge([
            'id' => $service->id,
            'category_id' => $service->category_id,
            'icon' => $this->uploadFilesService->getFullUrl($iconPath),
            'Service_name' => ServiceVendorOffering::displayNameForVendor($service, $vendorId, $locale),
            'Service_description' => ServiceVendorOffering::descriptionForVendor($service, $vendorId, $locale),
            'Category' => $service->category ? $service->category->getTranslation('name', $locale) : null,
            'rating' => round((float) $rating, 2),
            'vendor_id' => $vendorId,
            'in_vendor_catalog' => ServiceVendorOffering::find($vendorId, $service->id) !== null,
            'source' => $availableOnly ? 'available_for_vendor' : 'vendor_catalog',
        ], CatalogActivePresenter::service($service, null, null, $vendorId));
    }

    /**
     * Format service data for response
     */
    private function formatService($service, $locale = 'ar'): array
    {
        return [
            'id' => $service->id,
            'category_id' => $service->category_id,
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
            'is_active' => (bool) $service->is_active,
        ];
    }
}
