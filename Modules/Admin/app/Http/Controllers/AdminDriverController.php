<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Requests\StoreDriverRequest;
use Modules\Admin\Http\Requests\UpdateDriverRequest;
use Modules\Admin\Http\Resources\DriverResource;
use Modules\Admin\Services\DriverService;

class AdminDriverController extends Controller
{
    public function __construct(
        private DriverService $driverService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'is_available' => $request->is_available,
            'search' => $request->search,
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $drivers = $this->driverService->getAllPaginated(
            $filters,
            $request->input('per_page', 15)
        );

        return successResponse(
            DriverResource::collection($drivers),
            'Drivers retrieved successfully'
        );
    }

    public function store(StoreDriverRequest $request): JsonResponse
    {
        $driver = $this->driverService->create($request->validated());

        return successResponse(new DriverResource($driver), 'Driver created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        return successResponse(new DriverResource($driver), 'Driver retrieved successfully');
    }

    public function update(UpdateDriverRequest $request, int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        $driver = $this->driverService->update($id, $request->validated());

        return successResponse(new DriverResource($driver), 'Driver updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->driverService->delete($id);

        if (! $deleted) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        return successResponse(
            null,
            'Driver deleted successfully'
        );
    }

    public function toggleAvailability(int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        $driver->is_available = ! $driver->is_available;
        $driver->save();

        return successResponse(
            new DriverResource($driver),
            'Driver availability updated successfully'
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = $this->driverService->getStatistics();

        return successResponse(
            $stats,
            'Driver statistics retrieved successfully'
        );
    }

    public function ban(Request $request, int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $driver->update([
            'is_banned' => true,
            'ban_reason' => $request->input('reason'),
            'banned_at' => now(),
        ]);

        return successResponse(
            new DriverResource($driver->fresh()),
            'Driver banned successfully'
        );
    }

    public function unban(int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        $driver->update([
            'is_banned' => false,
            'ban_reason' => null,
            'banned_at' => null,
        ]);

        return successResponse(
            new DriverResource($driver->fresh()),
            'Driver unbanned successfully'
        );
    }
}
