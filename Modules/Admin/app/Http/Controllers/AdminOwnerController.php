<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Requests\StoreOwnerRequest;
use Modules\Admin\Http\Requests\UpdateOwnerRequest;
use Modules\Admin\Http\Resources\OwnerResource;
use Modules\Admin\Services\OwnerService;
use Modules\Owner\Models\Owner;

class AdminOwnerController extends Controller
{
    public function __construct(
        private OwnerService $ownerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'is_verified' => $request->is_verified,
            'search' => $request->search,
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $owners = $this->ownerService->getAllPaginated(
            $filters,
            $request->input('per_page', 15)
        );

        return successResponse(
            OwnerResource::collection($owners),
            'Owners retrieved successfully'
        );
    }

    public function store(StoreOwnerRequest $request): JsonResponse
    {
        $owner = $this->ownerService->create($request->validated());

        return successResponse(new OwnerResource($owner), 'Owner created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $owner = $this->ownerService->find($id);

        if (! $owner) {
            return ErrorResponse::make('Owner not found', null, 404);
        }

        return successResponse(new OwnerResource($owner), 'Owner retrieved successfully');
    }

    public function update(UpdateOwnerRequest $request, int $id): JsonResponse
    {
        $owner = $this->ownerService->find($id);

        if (! $owner) {
            return ErrorResponse::make('Owner not found', null, 404);
        }

        $owner = $this->ownerService->update($id, $request->validated());

        return successResponse(
            new OwnerResource($owner),
            'Owner updated successfully'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->ownerService->delete($id);

        if (! $deleted) {
            return ErrorResponse::make('Owner not found', null, 404);
        }

        return successResponse(
            null,
            'Owner deleted successfully'
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $owner = $this->ownerService->toggleStatus($id);

        if (! $owner) {
            return ErrorResponse::make('Owner not found', null, 404);
        }

        return successResponse(
            new OwnerResource($owner),
            'Owner status updated successfully'
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_owners' => Owner::count(),
            'verified_owners' => Owner::where('is_verified', true)->count(),
            'unverified_owners' => Owner::where('is_verified', false)->count(),
            'recent_owners' => Owner::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return successResponse(
            $stats,
            'Owner statistics retrieved successfully'
        );
    }
}
