<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Resources\ServiceResource;
use Modules\Service\Models\Service;

class AdminServiceController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Service::with(['category', 'categories']);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        if ($request->has('category_id')) {
            $catId = $request->category_id;
            $query->where(function ($q) use ($catId) {
                $q->where('category_id', $catId)
                    ->orWhereHas('categories', fn ($cq) => $cq->where('categories.id', $catId));
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereRaw("JSON_EXTRACT(service_name, '$.ar') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("JSON_EXTRACT(service_name, '$.en') LIKE ?", ["%{$search}%"]);
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $services = $query->paginate($request->input('per_page', 15));

        return successResponse(
            ServiceResource::collection($services),
            __('admin.services_retrieved')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'nullable|integer',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'name' => 'required|array',
            'name.ar' => 'required|string|max:255',
            'name.en' => 'required|string|max:255',
            'description' => 'nullable|array',
            'description.ar' => 'nullable|string',
            'description.en' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'price' => 'prohibited',
            'discount_price' => 'nullable|numeric|min:0',
            'preparation_time' => 'nullable|integer',
            'is_active' => 'boolean',
            'pieces' => 'nullable|array',
            'pieces.*.piece_id' => 'required_with:pieces|exists:pieces,id',
            'pieces.*.price' => 'required_with:pieces|numeric|min:0',
            'icon_id' => 'required|exists:icons,id',
        ]);

        $categoryIds = [];
        if (! empty($validated['category_ids'])) {
            $categoryIds = array_map('intval', $validated['category_ids']);
        } elseif (! empty($validated['category_id'])) {
            $categoryIds = [(int) $validated['category_id']];
        }

        $primaryCategoryId = ! empty($categoryIds) ? $categoryIds[0] : null;
        if (! $primaryCategoryId && isset($validated['category_id']) && \Illuminate\Support\Facades\DB::table('categories')->where('id', $validated['category_id'])->exists()) {
            $primaryCategoryId = (int) $validated['category_id'];
        }

        $serviceData = [
            'category_id' => $primaryCategoryId,
            'service_name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'discount_price' => $validated['discount_price'] ?? null,
            'preparation_time' => $validated['preparation_time'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'icon_id' => $validated['icon_id'] ?? null,
        ];

        // Handle image upload
        if ($request->hasFile('image')) {
            $serviceData['image'] = $this->uploadFilesService->uploadImage(
                $request->file('image'),
                'services/images'
            );
        }

        $service = Service::create($serviceData);

        // Sync many-to-many categories
        if (! empty($categoryIds)) {
            $service->categories()->sync($categoryIds);
        }

        // attach pieces with optional pivot prices
        if ($request->has('pieces') && is_array($request->pieces)) {
            $syncData = [];
            foreach ($request->pieces as $p) {
                if (isset($p['piece_id'])) {
                    $syncData[$p['piece_id']] = ['price' => $p['price'] ?? null];
                }
            }
            if (! empty($syncData)) {
                $service->pieces()->sync($syncData);
                // cache invalidation: service pieces sync
                flushCacheTags(['services', 'categories', 'branches']);
            }
        }

        return successResponse(
            new ServiceResource($service->load(['category', 'categories'])),
            __('admin.service_created'),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $service = Service::with(['category', 'categories', 'additions', 'pieces'])->find($id);

        if (! $service) {
            return notFoundResponse(__('admin.service_not_found'));
        }

        return successResponse(
            $service,
            __('admin.service_retrieved')
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $service = Service::find($id);

        if (! $service) {
            return notFoundResponse(__('admin.service_not_found'));
        }

        $validated = $request->validate([
            'category_id' => 'sometimes|nullable|exists:categories,id',
            'category_ids' => 'sometimes|nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'name' => 'sometimes|array',
            'name.ar' => 'sometimes|string|max:255',
            'name.en' => 'sometimes|string|max:255',
            'description' => 'nullable|array',
            'description.ar' => 'nullable|string',
            'description.en' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'price' => 'prohibited',
            'discount_price' => 'nullable|numeric|min:0',
            'preparation_time' => 'nullable|integer',
            'is_active' => 'boolean',
            'pieces' => 'nullable|array',
            'pieces.*.piece_id' => 'required_with:pieces|exists:pieces,id',
            'pieces.*.price' => 'required_with:pieces|numeric|min:0',
            'icon_id' => 'sometimes|required|exists:icons,id',
        ]);

        $data = [];

        if (array_key_exists('category_ids', $validated) && is_array($validated['category_ids'])) {
            $catIds = array_map('intval', $validated['category_ids']);
            $service->categories()->sync($catIds);
            if (! empty($catIds)) {
                $data['category_id'] = $catIds[0];
            }
        } elseif (array_key_exists('category_id', $validated)) {
            $data['category_id'] = $validated['category_id'];
            if ($validated['category_id']) {
                $service->categories()->syncWithoutDetaching([(int) $validated['category_id']]);
            }
        }

        if (isset($validated['name'])) {
            $data['service_name'] = $validated['name'];
        }

        if (isset($validated['description'])) {
            $data['description'] = $validated['description'];
        }

        if (array_key_exists('discount_price', $validated)) {
            $data['discount_price'] = $validated['discount_price'];
        }

        if (isset($validated['preparation_time'])) {
            $data['preparation_time'] = $validated['preparation_time'];
        }

        if (isset($validated['is_active'])) {
            $data['is_active'] = $validated['is_active'];
        }

        if (isset($validated['icon_id'])) {
            $data['icon_id'] = $validated['icon_id'];
        }

        // Handle image upload

        if (! empty($data)) {
            $service->update($data);
        }

        // sync pieces if provided
        if ($request->has('pieces') && is_array($request->pieces)) {
            $syncData = [];
            foreach ($request->pieces as $p) {
                if (isset($p['piece_id'])) {
                    $syncData[$p['piece_id']] = ['price' => $p['price'] ?? null];
                }
            }
            $service->pieces()->sync($syncData);
            // cache invalidation: service pieces sync
            flushCacheTags(['services', 'categories', 'branches']);
        }

        return successResponse(
            new ServiceResource($service->fresh()->load(['category', 'categories'])),
            __('admin.service_updated')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $service = Service::find($id);

        if (! $service) {
            return notFoundResponse(__('admin.service_not_found'));
        }

        $service->delete();

        return successResponse(
            null,
            __('admin.service_deleted')
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $service = Service::find($id);

        if (! $service) {
            return notFoundResponse(__('admin.service_not_found'));
        }

        $service->is_active = ! $service->is_active;
        $service->save();

        return successResponse(
            $service,
            __('admin.service_status_updated')
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_services' => Service::count(),
            'active_services' => Service::count(),
            'services_on_discount' => Service::whereNotNull('discount_price')->count(),
        ];

        return successResponse(
            $stats,
            __('admin.service_statistics_retrieved')
        );
    }
}
