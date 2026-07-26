<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use Modules\Vendor\Models\Vendor;

class AdminLaundryAdditionalServiceController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get additional services for a laundry
     * GET /laundries/additional_services
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');

        if (! $vendorId) {
            return errorResponse('Vendor ID is required', null, 400);
        }

        $vendor = Vendor::find($vendorId);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        // Get additional services for this vendor
        $additionalServices = ServiceAddition::where('vendor_id', $vendorId)
            ->with(['services', 'pieces'])
            ->paginate($request->input('per_page', 15));

        $servicesData = $additionalServices->getCollection()->map(function ($service) {
            $locale = app()->getLocale();

            // Get pieces directly associated with this additional service
            $pieces = $service->pieces()
                ->get()
                ->map(function ($piece) use ($locale) {
                    return $piece->getTranslation('name', $locale);
                })
                ->toArray();

            $category = null;
            if ($service->services->isNotEmpty()) {
                $category = $service->services->first()->category;
            }

            return [
                'id' => $service->id,
                'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                'name' => $service->getTranslation('name', $locale),
                'description' => $service->getTranslation('description', $locale),
                'Category' => $category ? $category->getTranslation('name', $locale) : null,
                'Price' => (float) $service->price,
                'is_active' => (bool) $service->is_active,
                'Pieces' => $pieces,
            ];
        });

        $additionalServices->setCollection($servicesData);

        return successResponse($additionalServices, 'Additional services retrieved successfully');
    }

    /**
     * Get single additional service
     * GET /laundries/additional_services/:id
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $service = ServiceAddition::with(['services', 'pieces'])->find($id);

        if (! $service) {
            return notFoundResponse('Additional service not found');
        }

        // Get pieces directly associated with this additional service
        $pieces = $service->pieces()
            ->get()
            ->map(function ($piece) {
                return [
                    'ar' => $piece->getTranslation('name', 'ar'),
                    'en' => $piece->getTranslation('name', 'en'),
                ];
            })
            ->toArray();

        $category = null;
        if ($service->services->isNotEmpty()) {
            $category = $service->services->first()->category;
        }

        $serviceData = [
            'id' => $service->id,
            'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
            'service_name' => [
                'ar' => $service->getTranslation('name', 'ar'),
                'en' => $service->getTranslation('name', 'en'),
            ],
            'Service_description' => [
                'ar' => $service->getTranslation('description', 'ar'),
                'en' => $service->getTranslation('description', 'en'),
            ],
            'Category' => $category ? [
                'ar' => $category->getTranslation('name', 'ar'),
                'en' => $category->getTranslation('name', 'en'),
            ] : null,
            'Price' => (float) $service->price,
            'is_active' => (bool) $service->is_active,
            'icon_id' => $service->icon_id,
            'Pieces' => $pieces,
        ];

        return successResponse($serviceData, 'Additional service retrieved successfully');
    }

    /**
     * Create additional service
     * POST /laundries/additional_services
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'service_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'service_name' => 'required|array',
            'service_name.ar' => 'required|string|max:255',
            'service_name.en' => 'required|string|max:255',
            'Service_description' => 'nullable|array',
            'Service_description.ar' => 'nullable|string',
            'Service_description.en' => 'nullable|string',
            'Category' => 'nullable|string',
            'Price' => 'required|numeric|min:0',
            'icon_id' => 'required|exists:icons,id',
            'Pieces' => 'nullable|array',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'exists:services,id',
        ]);

        $serviceData = [
            'vendor_id' => $validated['vendor_id'],
            'name' => $validated['service_name'],
            'description' => $validated['Service_description'] ?? null,
            'price' => $validated['Price'],
            'icon_id' => $validated['icon_id'] ?? null,
            'is_active' => true,
        ];

        // Handle logo upload (this is for the image field)
        if ($request->hasFile('service_logo')) {
            $serviceData['image'] = $this->uploadFilesService->uploadImage(
                $request->file('service_logo'),
                'additional-services/images'
            );
        }

        $service = ServiceAddition::create($serviceData);

        // Link to specific services if provided
        if (! empty($validated['service_ids'])) {
            $service->services()->attach($validated['service_ids']);
        }

        return successResponse($this->formatAdditionalService($service->fresh(['services', 'pieces'])), 'Additional service created successfully', 201);
    }

    /**
     * Update additional service
     * PUT /laundries/additional_services/:id
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $service = ServiceAddition::find($id);

        if (! $service) {
            return notFoundResponse('Additional service not found');
        }

        $validated = $request->validate([
            'service_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'service_name' => 'sometimes|array',
            'service_name.ar' => 'sometimes|string|max:255',
            'service_name.en' => 'sometimes|string|max:255',
            'Service_description' => 'nullable|array',
            'Service_description.ar' => 'nullable|string',
            'Service_description.en' => 'nullable|string',
            'Category' => 'nullable|string',
            'Price' => 'sometimes|numeric|min:0',
            'icon_id' => 'sometimes|required|exists:icons,id',
            'Pieces' => 'nullable|array',
        ]);

        $serviceData = [];

        if (isset($validated['service_name'])) {
            $serviceData['name'] = $validated['service_name'];
        }

        if (isset($validated['Service_description'])) {
            $serviceData['description'] = $validated['Service_description'];
        }

        if (isset($validated['Price'])) {
            $serviceData['price'] = $validated['Price'];
        }

        if (isset($validated['icon_id'])) {
            $serviceData['icon_id'] = $validated['icon_id'];
        }

        $service->update($serviceData);

        return successResponse($this->formatAdditionalService($service->fresh()), 'Additional service updated successfully');
    }

    /**
     * Delete additional service
     * DELETE /laundries/additional_services/:id
     */
    public function destroy(int $id): JsonResponse
    {
        $service = ServiceAddition::find($id);

        if (! $service) {
            return notFoundResponse('Additional service not found');
        }

        $service->delete();

        return successResponse(null, 'Additional service deleted successfully');
    }

    /**
     * Format additional service data for response
     */
    private function formatAdditionalService($service): array
    {
        // Get pieces directly associated with this additional service
        $pieces = $service->pieces()
            ->get()
            ->map(function ($piece) {
                return [
                    'ar' => $piece->getTranslation('name', 'ar'),
                    'en' => $piece->getTranslation('name', 'en'),
                ];
            })
            ->toArray();

        $category = null;
        if ($service->services->isNotEmpty()) {
            $category = $service->services->first()->category;
        }

        return [
            'id' => $service->id,
            'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
            'service_name' => [
                'ar' => $service->getTranslation('name', 'ar'),
                'en' => $service->getTranslation('name', 'en'),
            ],
            'Service_description' => [
                'ar' => $service->getTranslation('description', 'ar'),
                'en' => $service->getTranslation('description', 'en'),
            ],
            'Category' => $category ? [
                'ar' => $category->getTranslation('name', 'ar'),
                'en' => $category->getTranslation('name', 'en'),
            ] : null,
            'Price' => (float) $service->price,
            'is_active' => (bool) $service->is_active,
            'icon_id' => $service->icon_id,
            'Pieces' => $pieces,
        ];
    }
}
