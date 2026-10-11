<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Requests\StoreDriverRequest;
use Modules\Admin\Http\Requests\UpdateDriverRequest;
use Modules\Admin\Http\Resources\DriverResource;
use Modules\Admin\Services\DriverService;

class AdminDriverController extends Controller
{
    public function __construct(
        private DriverService $driverService,
        private UploadFilesService $uploadFilesService
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
            __('admin.drivers_retrieved')
        );
    }

    public function store(StoreDriverRequest $request): JsonResponse
    {
        $data = $request->validated();

        $data['image'] = $this->uploadFilesService->uploadImage($request->file('image'), 'drivers/images');
        $data['image_document'] = $this->uploadFilesService->uploadFile($request->file('image_document'), 'drivers/documents');

        $driver = $this->driverService->create($data);

        return successResponse(new DriverResource($driver), __('admin.driver_created'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make(__('admin.driver_not_found'), null, 404);
        }

        return successResponse(new DriverResource($driver), __('admin.driver_retrieved'));
    }

    public function update(UpdateDriverRequest $request, int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make(__('admin.driver_not_found'), null, 404);
        }

        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadFilesService->uploadImage($request->file('image'), 'drivers/images', $driver->image);
        } else {
            unset($data['image']);
        }

        if ($request->hasFile('image_document')) {
            $data['image_document'] = $this->uploadFilesService->uploadFile($request->file('image_document'), 'drivers/documents', $driver->image_document);
        } else {
            unset($data['image_document']);
        }

        $driver = $this->driverService->update($id, $data);

        return successResponse(new DriverResource($driver), __('admin.driver_updated'));
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->driverService->delete($id);

        if (! $deleted) {
            return ErrorResponse::make(__('admin.driver_not_found'), null, 404);
        }

        return successResponse(
            null,
            __('admin.driver_deleted')
        );
    }

    public function toggleAvailability(int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make(__('admin.driver_not_found'), null, 404);
        }

        $driver->is_available = ! $driver->is_available;
        $driver->save();

        return successResponse(
            new DriverResource($driver),
            __('admin.driver_availability_updated')
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = $this->driverService->getStatistics();

        return successResponse(
            $stats,
            __('admin.driver_statistics_retrieved')
        );
    }

    public function ban(Request $request, int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make(__('admin.driver_not_found'), null, 404);
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
            __('admin.driver_banned')
        );
    }

    public function unban(int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make(__('admin.driver_not_found'), null, 404);
        }

        $driver->update([
            'is_banned' => false,
            'ban_reason' => null,
            'banned_at' => null,
        ]);

        return successResponse(
            new DriverResource($driver->fresh()),
            __('admin.driver_unbanned')
        );
    }
}
