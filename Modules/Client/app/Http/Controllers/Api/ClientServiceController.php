<?php

namespace Modules\Client\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceFilterRequest;
use Illuminate\Http\JsonResponse;

class ClientServiceController extends Controller
{
    /**
     * Get all available services in client's zone
     * Returns services that are available in branches within the zone
     */
    public function getAvailableServices(ServiceFilterRequest $request): JsonResponse
    {
        try {
            $locale = app()->getLocale();
            $user = $request->user();

            // Get zone_id from header or client's default address
            $zoneId = getZoneIdFromRequest($request);

            // Require either zone-id header or authentication
            if (! $zoneId && ! $user) {
                return errorResponse(__('service.zone_id_required'), null, 400);
            }

            // Require zone_id
            if (! $zoneId) {
                return errorResponse(
                    'Please provide zone-id in header or set a default address',
                    null,
                    400
                );
            }

            $tags = ['services', 'branches', 'vendors', 'zones', 'categories'];
            $versionKey = getTagVersionKey($tags);
            $cacheKey = "client:v1:zone:{$zoneId}:available_services:v{$versionKey}:{$locale}";

            $result = \Illuminate\Support\Facades\Cache::remember($cacheKey, 1800, function () use ($zoneId, $locale) {
                // Build query for active services available in branches within the zone
                $query = \Modules\Service\Models\Service::where('services.is_active', true)
                    ->whereHas('branches', function ($branchQuery) use ($zoneId) {
                        $branchQuery->where('branches.zone_id', $zoneId)
                            ->where('branches.is_active', true)
                            ->whereHas('vendor', function ($vendorQuery) {
                                $vendorQuery->where('vendors.is_active', true)
                                    ->where(function ($q) {
                                        $q->where('vendors.is_banned', false)->orWhereNull('vendors.is_banned');
                                    });
                            });
                    })
                    ->with(['category'])
                    ->orderBy('services.order', 'asc');

                // Get services with branch count
                $services = $query->get()
                    ->map(function ($service) use ($locale, $zoneId) {
                        $uploadService = app(\App\Services\UploadFilesService::class);

                        // Count how many branches offer this service in the zone
                        $branchCount = \Modules\Branch\Models\Branch::where('branches.zone_id', $zoneId)
                            ->where('branches.is_active', true)
                            ->whereHas('services', function ($q) use ($service) {
                                $q->where('services.id', $service->id);
                            })
                            ->whereHas('vendor', function ($vendorQuery) {
                                $vendorQuery->where('vendors.is_active', true)
                                    ->where(function ($q) {
                                        $q->where('vendors.is_banned', false)->orWhereNull('vendors.is_banned');
                                    });
                            })
                            ->count();

                        // Get category name safely
                        $categoryName = null;
                        if ($service->category && method_exists($service->category, 'getTranslation')) {
                            $categoryName = $service->category->getTranslation('name', $locale);
                        }

                        return [
                            'id' => $service->id,
                            'name' => method_exists($service, 'getTranslation')
                                ? $service->getTranslation('service_name', $locale)
                                : $service->service_name,

                            'icon' => $uploadService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                            'icon_id' => $service->icon_id,
                            'category_id' => $service->category_id,
                            'category_name' => $categoryName,
                            'price' => (float) $service->price,
                            'available_branches_count' => $branchCount,
                        ];
                    })
                    ->values();

                return [
                    'zone_id' => $zoneId,
                    'services' => $services,
                    'total_count' => $services->count(),
                ];
            });

            return successResponse(
                $result,
                'Available services retrieved successfully'
            );
        } catch (\Exception $e) {
            return errorResponse(
                'Error retrieving available services',
                ['error' => $e->getMessage()],
                500
            );
        }
    }
}
