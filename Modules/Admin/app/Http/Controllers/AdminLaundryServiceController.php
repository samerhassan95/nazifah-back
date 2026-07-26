<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Service\Models\Service;

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
     */
    public function index(Request $request): JsonResponse
    {
        $services = Service::with(['category'])
            ->paginate($request->input('per_page', 15));

        $servicesData = $services->getCollection()->map(function ($service) {
            $locale = app()->getLocale();

            return [
                'id' => $service->id,
                'category_id' => $service->category_id,
                'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                'Service_name' => $service->getTranslation('service_name', $locale),
                'Service_description' => $service->getTranslation('description', $locale),
                'Category' => $service->category ? $service->category->getTranslation('name', $locale) : null,
                'Price' => (float) $service->price,
                'is_active' => (bool) $service->is_active,
            ];
        });

        $services->setCollection($servicesData);

        return successResponse($services, __('vendor::vendor.services_retrieved_successfully'));
    }

    /**
     * Get single service
     * GET /laundries/services/:id
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $service = Service::with(['category', 'pieces'])->find($id);

        if (! $service) {
            return notFoundResponse(__('service::service.not_found'));
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
            'Price' => (float) $service->price,
            'is_active' => (bool) $service->is_active,
        ];

        return successResponse($serviceData, __('vendor::vendor.service_retrieved_successfully'));
    }

    /**
     * Create service
     * POST /laundries/services
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'service_name' => 'required|array',
            'service_name.ar' => 'required|string|max:255',
            'service_name.en' => 'required|string|max:255',
            'service_description' => 'nullable|array',
            'service_description.ar' => 'nullable|string',
            'service_description.en' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric|min:0',
        ]);

        $serviceData = [
            'service_name' => $validated['service_name'],
            'description' => $validated['service_description'] ?? null,
            'category_id' => $validated['category_id'],
            'price' => $validated['price'],
            'is_active' => true,
        ];

        // Handle logo upload
        if ($request->hasFile('service_logo')) {
            $serviceData['image'] = $this->uploadFilesService->uploadImage(
                $request->file('service_logo'),
                'services/images'
            );
        }

        $service = Service::create($serviceData);
        $locale = app()->getLocale();

        return successResponse(
            $this->formatService($service, $locale),
            __('service::service.created_successfully'),
            201
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
            return notFoundResponse(__('service::service.not_found'));
        }

        $validated = $request->validate([
            'service_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'service_name' => 'sometimes|array',
            'service_name.ar' => 'sometimes|string|max:255',
            'service_name.en' => 'sometimes|string|max:255',
            'service_description' => 'nullable|array',
            'service_description.ar' => 'nullable|string',
            'service_description.en' => 'nullable|string',
            'category_id' => 'sometimes|exists:categories,id',
            'price' => 'sometimes|numeric|min:0',
            'branches' => 'nullable|array',
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

        if (isset($validated['price'])) {
            $serviceData['price'] = $validated['price'];
        }

        $service->update($serviceData);

        $locale = app()->getLocale();

        return successResponse(
            $this->formatService($service->fresh(), $locale),
            __('service::service.updated_successfully')
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
            return notFoundResponse(__('service::service.not_found'));
        }

        $service->delete();

        return successResponse(null, __('service::service.deleted_successfully'));
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
            'Price' => (float) $service->price,
            'is_active' => (bool) $service->is_active,
        ];
    }
}
