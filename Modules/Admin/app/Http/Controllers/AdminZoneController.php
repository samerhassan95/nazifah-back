<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Requests\StoreZoneRequest;
use Modules\Admin\Http\Requests\UpdateZoneRequest;
use Modules\Admin\Http\Resources\ZoneResource;
use Modules\Admin\Services\ZoneService;

class AdminZoneController extends Controller
{
    public function __construct(
        private ZoneService $zoneService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'is_active' => $request->is_active,
            'search' => $request->search,
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $zones = $this->zoneService->getAllPaginated(
            $filters,
            $request->input('per_page', 15)
        );

        $statistics = $this->zoneService->getStatistics();

        return successResponse(
            ZoneResource::collection($zones),
            'Zones retrieved successfully',
            200,
            [
                'statistics' => $statistics,
            ]
        );
    }

    public function store(StoreZoneRequest $request): JsonResponse
    {
        $zone = $this->zoneService->create($request->validated());

        return successResponse(new ZoneResource($zone), 'Zone created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $zone = $this->zoneService->find($id);

        if (! $zone) {
            return ErrorResponse::make('Zone not found', null, 404);
        }

        return successResponse(new ZoneResource($zone), 'Zone retrieved successfully');
    }

    public function update(UpdateZoneRequest $request, int $id): JsonResponse
    {
        $zone = $this->zoneService->find($id);

        if (! $zone) {
            return ErrorResponse::make('Zone not found', null, 404);
        }

        $zone = $this->zoneService->update($id, $request->validated());

        return successResponse(
            new ZoneResource($zone),
            'Zone updated successfully'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->zoneService->delete($id);

        if (! $deleted) {
            return ErrorResponse::make('Zone not found', null, 404);
        }

        return successResponse(
            null,
            'Zone deleted successfully'
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $zone = $this->zoneService->toggleStatus($id);

        if (! $zone) {
            return ErrorResponse::make('Zone not found', null, 404);
        }

        return successResponse(
            new ZoneResource($zone),
            'Zone status updated successfully'
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = $this->zoneService->getStatistics();

        return successResponse(
            $stats,
            'Zone statistics retrieved successfully'
        );
    }
}
