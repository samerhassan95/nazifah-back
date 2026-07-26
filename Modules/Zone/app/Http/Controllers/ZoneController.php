<?php

namespace Modules\Zone\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Zone\Http\Requests\StoreZoneRequest;
use Modules\Zone\Http\Requests\UpdateZoneRequest;
use Modules\Zone\Http\Resources\ZoneResource;
use Modules\Zone\Services\ZoneService;

class ZoneController extends Controller
{
    public function __construct(
        private ZoneService $zoneService
    ) {}

    /**
     * Display a listing of zones
     */
    public function index(): JsonResponse
    {
        $zones = $this->zoneService->getAllZones(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Zones retrieved successfully',
            'data' => [
                'zones' => ZoneResource::collection($zones),
            ],
        ]);
    }

    /**
     * Get zone details by ID
     */
    public function show($id): JsonResponse
    {
        $zone = $this->zoneService->getZoneById($id);

        if (! $zone) {
            return response()->json([
                'success' => false,
                'message' => 'Zone not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Zone details retrieved successfully',
            'data' => [
                'zone' => new ZoneResource($zone),
            ],
        ]);
    }

    /**
     * Store a newly created zone.
     */
    public function store(StoreZoneRequest $request): JsonResponse
    {
        $zone = $this->zoneService->createZone($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Zone created successfully',
            'data' => [
                'zone' => new ZoneResource($zone),
            ],
        ], 201);
    }

    /**
     * Update an existing zone.
     */
    public function update(UpdateZoneRequest $request, $id): JsonResponse
    {
        $zone = $this->zoneService->updateZone((int) $id, $request->validated());

        if (! $zone) {
            return response()->json([
                'success' => false,
                'message' => 'Zone not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Zone updated successfully',
            'data' => [
                'zone' => new ZoneResource($zone),
            ],
        ]);
    }

    /**
     * Remove a zone.
     */
    public function destroy($id): JsonResponse
    {
        $deleted = $this->zoneService->deleteZone((int) $id);

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Zone not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Zone deleted successfully',
        ]);
    }

    /**
     * Find zone by coordinates
     */
    public function findByCoordinates(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $zone = $this->zoneService->findByCoordinates(
            $request->latitude,
            $request->longitude
        );

        if (! $zone) {
            return response()->json([
                'success' => false,
                'message' => 'No zone found for the provided coordinates',
                'data' => [
                    'coordinates' => [
                        'latitude' => $request->latitude,
                        'longitude' => $request->longitude,
                    ],
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Zone found successfully',
            'data' => [
                'zone' => new ZoneResource($zone),
                'coordinates' => [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                ],
            ],
        ]);
    }

    /**
     * Get vendors in a specific zone
     */
    public function getVendors($zoneId): JsonResponse
    {
        $zone = $this->zoneService->getZoneById($zoneId);

        if (! $zone || ! $zone->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Zone not found',
            ], 404);
        }

        $vendors = $zone->vendors()
            ->where('is_active', true)
            ->with(['category'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Vendors in zone retrieved successfully',
            'data' => [
                'zone' => $zone->only(['id', 'name', 'delivery_fee', 'minimum_order', 'zone_color']),
                'vendors' => $vendors,
                'total_vendors' => $vendors->count(),
            ],
        ]);
    }

    /**
     * Get vendors by category in a specific zone
     * Note: Vendors are now linked to categories through services
     */
    public function getVendorsByCategory($zoneId, $categoryId): JsonResponse
    {
        $zone = $this->zoneService->getZoneById($zoneId);

        if (! $zone || ! $zone->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Zone not found',
            ], 404);
        }

        // Get vendors through their branches that offer services in this category
        $vendors = $zone->vendors()
            ->where('is_active', true)
            ->whereHas('branches.services', function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Vendors by category in zone retrieved successfully',
            'data' => [
                'zone' => $zone->only(['id', 'name', 'delivery_fee', 'minimum_order', 'zone_color']),
                'category_id' => $categoryId,
                'vendors' => $vendors,
                'total_vendors' => $vendors->count(),
            ],
        ]);
    }
}
