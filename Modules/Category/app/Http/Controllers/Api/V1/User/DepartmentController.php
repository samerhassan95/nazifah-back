<?php

namespace Modules\Category\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Category\Models\Category;
use Modules\Service\Models\Service;
use Modules\Vendor\Models\Vendor;

class DepartmentController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    public function index(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $perPage = $request->per_page ?? 15;
        $page = $request->page ?? 1;
        $tags = ['departments', 'categories'];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "user:v1:departments:{$versionKey}:{$locale}:p{$perPage}:page{$page}";

        $departments = Cache::remember($cacheKey, 3600, function () use ($perPage) {
            $query = Category::where('is_active', true)
                ->orderBy('order', 'asc');

            $results = $query->paginate($perPage);

            $results->getCollection()->transform(function ($department) {
                return [
                    'id' => $department->id,
                    'title' => $department->name,
                    'image' => $this->uploadFilesService->getFullUrl($department->image),
                    'icon' => $department->iconRelation ? $this->uploadFilesService->getFullUrl($department->iconRelation->path) : null,
                    'description' => $department->description,
                ];
            });

            return $results;
        });

        return successResponse($departments, __('category.departments_retrieved'));
    }

    /**
     * Get services in department
     */
    public function getServices(Request $request, int $department_id): JsonResponse
    {
        $locale = app()->getLocale();
        $perPage = $request->per_page ?? 15;
        $page = $request->page ?? 1;
        $cacheKey = "user:v1:department:{$department_id}:services:{$locale}:p{$perPage}:page{$page}";

        $department = Category::find($department_id);

        if (! $department) {
            return notFoundResponse(__('category.department_not_found'));
        }

        $tags = ['services', 'categories', "category_{$department_id}"];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "user:v1:department:{$department_id}:services:{$versionKey}:{$locale}:p{$perPage}:page{$page}";

        $services = Cache::remember($cacheKey, 3600, function () use ($department, $perPage) {
            $query = Service::with('category')
                ->orderBy('order', 'asc');

            // Filter by department (category) using category_id
            $query->where('category_id', $department->id);

            $results = $query->paginate($perPage);

            $results->getCollection()->transform(function ($service) {
                return [
                    'service_id' => $service->id,
                    'name' => $service->service_name,
                    'category' => $service->category ? $service->category->name : null,
                    'rating' => 4.5, // Calculate from reviews
                    'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                    'base_price' => (float) $service->price,
                ];
            });

            return $results;
        });

        return successResponse($services, __('category.department_services_retrieved'));
    }

    /**
     * Get service item details
     */
    public function getServiceItems(Request $request, int $vendor_id, int $service_id): JsonResponse
    {
        // Verify vendor exists and load branches for location data
        $vendor = Vendor::with('branches')->find($vendor_id);
        if (! $vendor) {
            return notFoundResponse(__('category.vendor_not_found'));
        }

        // Verify service exists
        $service = Service::find($service_id);
        if (! $service) {
            return notFoundResponse(__('service.service_not_found'));
        }

        // Get item types (pieces/garments) filtered by vendor and service
        $itemTypesQuery = $service->pieces()
            ->where('pieces.vendor_id', $vendor_id);

        // Get first active branch for location and service_piece pricing
        $firstBranch = $vendor->branches->where('is_active', true)->first();
        $firstBranchId = $firstBranch?->id;

        $itemTypes = $itemTypesQuery->orderBy('pieces.order', 'asc')
            ->get()
            ->map(function ($piece) use ($service, $firstBranchId) {
                $options = $this->getServiceOptions($service->id);

                $servicePiecePrice = $firstBranchId !== null
                    ? (float) $service->getPriceForPieceAtBranch($piece->id, $firstBranchId)
                    : (float) ($piece->pivot->price ?? $service->price ?? 0);

                return [
                    'item_type_id' => $piece->id,
                    'vendor_id' => $piece->vendor_id,
                    'name' => $piece->name,
                    'icon' => $this->uploadFilesService->getFullUrl($piece->icon),
                    'options' => $options,
                    'service_id' => $service->id,
                    'price' => $servicePiecePrice,
                ];
            });

        return successResponse([
            'service' => [
                'id' => $service->id,
                'name' => $service->service_name,
            ],
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
                'cover_img' => $this->uploadFilesService->getFullUrl($vendor->cover_image ?? null),
                'address_text' => $vendor->location ?? null,
                'location_gps' => [
                    'latitude' => $firstBranch ? (float) $firstBranch->latitude : 0,
                    'longitude' => $firstBranch ? (float) $firstBranch->longitude : 0,
                ],
            ],
            'item_types' => $itemTypes,
        ], __('category.service_item_details_retrieved'));
    }

    /**
     * Get service options/modifiers for a piece
     */
    private function getServiceOptions(int $service_id): array
    {
        // Fetch service additions from DB for the given service.
        $service = Service::with('additions')->find($service_id);

        if (! $service) {
            return [];
        }

        return $service->additions->map(fn ($addition) => [
            'modifier_id' => $addition->id,
            'extra_service' => $addition->name,
            'service_price' => isset($addition->price) ? (float) $addition->price : null,
        ])->values()->toArray();
    }
}
