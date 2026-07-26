<?php

namespace Modules\Owner\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Owner\Http\Requests\StoreOwnerRequest;
use Modules\Owner\Http\Requests\UpdateOwnerRequest;
use Modules\Owner\Http\Resources\OwnerResource;
use Modules\Owner\Services\OwnerService;

class OwnerController extends Controller
{
    public function __construct(private OwnerService $ownerService) {}

    public function index(): JsonResponse
    {
        $owners = $this->ownerService->getAllOwners();

        return successResponse(
            OwnerResource::collection($owners),
            'Owners retrieved successfully'
        );
    }

    public function store(StoreOwnerRequest $request): JsonResponse
    {
        $owner = $this->ownerService->createOwner($request->validated());

        return successResponse(new OwnerResource($owner), 'Owner created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $owner = $this->ownerService->getOwnerById($id);
        if (! $owner) {
            return notFoundResponse('Owner not found');
        }

        return successResponse(new OwnerResource($owner), 'Owner retrieved successfully');
    }

    public function update(UpdateOwnerRequest $request, int $id): JsonResponse
    {
        $owner = $this->ownerService->updateOwner($id, $request->validated());
        if (! $owner) {
            return notFoundResponse('Owner not found');
        }

        return successResponse(new OwnerResource($owner), 'Owner updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->ownerService->deleteOwner($id);
        if (! $deleted) {
            return notFoundResponse('Owner not found');
        }

        return successResponse(null, 'Owner deleted successfully');
    }
}
