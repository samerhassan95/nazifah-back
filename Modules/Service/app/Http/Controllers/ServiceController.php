<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Service\Http\Requests\StoreServiceRequest;
use Modules\Service\Http\Requests\UpdateServiceRequest;
use Modules\Service\Http\Resources\ServiceResource;
use Modules\Service\Services\ServiceService;

class ServiceController extends Controller
{
    public function __construct(private ServiceService $serviceService) {}

    public function index(): JsonResponse
    {
        $services = $this->serviceService->getAllServices();

        return successResponse(
            ServiceResource::collection($services),
            'Services retrieved successfully'
        );
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $service = $this->serviceService->createService($request->validated());

        return successResponse(new ServiceResource($service), 'Service created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $service = $this->serviceService->getServiceById($id);
        if (! $service) {
            return notFoundResponse('Service not found');
        }

        return successResponse(new ServiceResource($service), 'Service retrieved successfully');
    }

    public function update(UpdateServiceRequest $request, int $id): JsonResponse
    {
        $service = $this->serviceService->updateService($id, $request->validated());
        if (! $service) {
            return notFoundResponse('Service not found');
        }

        return successResponse(new ServiceResource($service), 'Service updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->serviceService->deleteService($id);
        if (! $deleted) {
            return notFoundResponse('Service not found');
        }

        return successResponse(null, 'Service deleted successfully');
    }

    /**
     * Get all branches that offer this service (filtered by zone when zone-id header is provided)
     * Requires zone-id header (or authenticated user's default address zone). Returns 400 if no zone.
     */
    public function getBranches(Request $request, int $id): JsonResponse
    {
        $zoneId = getZoneIdFromRequest($request);

        if (! $zoneId) {
            return errorResponse('Please select zone', null, 400);
        }

        $zone = \Modules\Zone\Models\Zone::where('id', $zoneId)->where('is_active', true)->first();
        if (! $zone) {
            return errorResponse('Please select a valid zone', null, 400);
        }

        $result = $this->serviceService->getBranchesByService($id, $zoneId);

        return successResponse($result, 'Branches retrieved successfully');
    }

    /**
     * Get all pieces for a specific service at a specific branch
     */
    public function getPiecesByServiceAndBranch(int $serviceId, int $branchId): JsonResponse
    {
        $pieces = $this->serviceService->getPiecesByServiceAndBranch($serviceId, $branchId);

        return successResponse($pieces, 'Pieces retrieved successfully');
    }
}
