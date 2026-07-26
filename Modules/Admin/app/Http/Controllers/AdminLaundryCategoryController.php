<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Models\Category;
use Modules\Service\Models\Service;
use Modules\Vendor\Models\Vendor;

class AdminLaundryCategoryController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get categories for a laundry
     * GET /laundries/categories
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');

        // If vendor_id is provided, filter by vendor's services
        if ($vendorId) {
            $vendor = Vendor::find($vendorId);

            if (! $vendor) {
                return notFoundResponse('Vendor not found');
            }

            // Get category IDs from services that belong to this vendor
            $categoryIds = Service::where('vendor_id', $vendorId)
                ->whereNotNull('category_id')
                ->pluck('category_id')
                ->unique();

            // Get categories with their services for this vendor
            $categories = Category::whereIn('id', $categoryIds)
                ->with(['services' => function ($query) use ($vendorId) {
                    $query->where('vendor_id', $vendorId);
                }, 'iconRelation'])
                ->paginate($request->input('per_page', 15));
        } else {
            // Get all categories
            $categories = Category::with(['services', 'iconRelation'])
                ->paginate($request->input('per_page', 15));
        }

        $categoriesData = $categories->getCollection()->map(function ($category) {
            $locale = app()->getLocale();

            $services = $category->services->map(function ($service) use ($locale) {
                return $service->getTranslation('service_name', $locale);
            })->toArray();

            return [
                'id' => $category->id,
                'image' => $this->uploadFilesService->getFullUrl($category->image),
                'name' => $category->getTranslation('name', $locale),
                'description' => $category->getTranslation('description', $locale),
                'icon_id' => $category->icon_id,
                'icon' => $category->iconRelation ? $category->iconRelation->full_path : null,
                'is_active' => (bool) $category->is_active,
                'Services' => $services,
            ];
        });

        $categories->setCollection($categoriesData);

        return successResponse($categories, 'Categories retrieved successfully');
    }

    /**
     * Get single category
     * GET /laundries/categories/:id
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $category = Category::with(['services', 'iconRelation'])->find($id);

        if (! $category) {
            return notFoundResponse('Category not found');
        }

        $vendorId = $request->input('vendor_id');
        $services = [];

        if ($vendorId) {
            $services = Service::where('category_id', $category->id)
                ->where('vendor_id', $vendorId)
                ->get()
                ->map(function ($service) {
                    return [
                        'ar' => $service->getTranslation('service_name', 'ar'),
                        'en' => $service->getTranslation('service_name', 'en'),
                    ];
                })
                ->toArray();
        } else {
            $services = $category->services->map(function ($service) {
                return [
                    'ar' => $service->getTranslation('service_name', 'ar'),
                    'en' => $service->getTranslation('service_name', 'en'),
                ];
            })->toArray();
        }

        $categoryData = [
            'id' => $category->id,
            'image' => $this->uploadFilesService->getFullUrl($category->image),
            'category_name' => [
                'ar' => $category->getTranslation('name', 'ar'),
                'en' => $category->getTranslation('name', 'en'),
            ],
            'description' => [
                'ar' => $category->getTranslation('description', 'ar'),
                'en' => $category->getTranslation('description', 'en'),
            ],
            'icon_id' => $category->icon_id,
            'icon' => $category->iconRelation ? $category->iconRelation->full_path : null,
            'is_active' => (bool) $category->is_active,
            'Services' => $services,
        ];

        return successResponse($categoryData, 'Category retrieved successfully');
    }

    /**
     * Create category
     * POST /laundries/categories
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'category_name' => 'required|array',
            'category_name.ar' => 'required|string|max:255',
            'category_name.en' => 'required|string|max:255',
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'icon_id' => 'required|exists:icons,id',
            'Services' => 'nullable|array',
        ]);

        $categoryData = [
            'name' => $validated['category_name'],
            'description' => $validated['description'] ?? null,
            'icon_id' => $validated['icon_id'],
            'is_active' => true,
        ];

        // Handle logo upload
        if ($request->hasFile('image')) {
            $categoryData['image'] = $this->uploadFilesService->uploadImage(
                $request->file('image'),
                'categories/images'
            );
        }

        $category = Category::create($categoryData);

        return successResponse($this->formatCategory($category), 'Category created successfully', 201);
    }

    /**
     * Update category
     * PUT /laundries/categories/:id
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return notFoundResponse('Category not found');
        }

        $validated = $request->validate([
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'category_name' => 'sometimes|array',
            'category_name.ar' => 'sometimes|string|max:255',
            'category_name.en' => 'sometimes|string|max:255',
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'icon_id' => 'sometimes|required|exists:icons,id',
            'Services' => 'nullable|array',
        ]);

        $categoryData = [];

        if (isset($validated['category_name'])) {
            $categoryData['name'] = $validated['category_name'];
        }

        if (array_key_exists('description', $validated)) {
            $categoryData['description'] = $validated['description'];
        }

        if (isset($validated['icon_id'])) {
            $categoryData['icon_id'] = $validated['icon_id'];
        }

        // Handle logo upload
        if ($request->hasFile('image')) {
            $categoryData['image'] = $this->uploadFilesService->uploadImage(
                $request->file('image'),
                'categories/images',
                $category->image
            );
        }

        $category->update($categoryData);

        return successResponse($this->formatCategory($category->fresh()), 'Category updated successfully');
    }

    /**
     * Delete category
     * DELETE /laundries/categories/:id
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return notFoundResponse('Category not found');
        }

        // Check if category has services
        if ($category->services()->count() > 0) {
            return errorResponse('Cannot delete category with associated services', 400);
        }

        $category->delete();

        return successResponse(null, 'Category deleted successfully');
    }

    /**
     * Format category data for response
     */
    private function formatCategory($category, $vendorId = null): array
    {
        $services = [];
        if ($vendorId) {
            $services = Service::where('category_id', $category->id)
                ->where('vendor_id', $vendorId)
                ->get()
                ->map(function ($service) {
                    return [
                        'ar' => $service->getTranslation('service_name', 'ar'),
                        'en' => $service->getTranslation('service_name', 'en'),
                    ];
                })
                ->toArray();
        } else {
            $services = $category->services->map(function ($service) {
                return [
                    'ar' => $service->getTranslation('service_name', 'ar'),
                    'en' => $service->getTranslation('service_name', 'en'),
                ];
            })->toArray();
        }

        // Load iconRelation if not already loaded
        if (! $category->relationLoaded('iconRelation')) {
            $category->load('iconRelation');
        }

        return [
            'id' => $category->id,
            'image' => $this->uploadFilesService->getFullUrl($category->image),
            'category_name' => [
                'ar' => $category->getTranslation('name', 'ar'),
                'en' => $category->getTranslation('name', 'en'),
            ],
            'description' => [
                'ar' => $category->getTranslation('description', 'ar'),
                'en' => $category->getTranslation('description', 'en'),
            ],
            'icon_id' => $category->icon_id,
            'icon' => $category->iconRelation ? $category->iconRelation->full_path : null,
            'is_active' => (bool) $category->is_active,
            'Services' => $services,
        ];
    }
}
