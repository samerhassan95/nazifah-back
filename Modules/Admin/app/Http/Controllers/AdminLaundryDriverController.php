<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Services\DriverService;
use Modules\Driver\Models\Driver;
use Modules\Vendor\Models\Vendor;

class AdminLaundryDriverController extends Controller
{
    public function __construct(
        private DriverService $driverService,
        private UploadFilesService $uploadFilesService
    ) {}

    /**
     * Get drivers for a laundry
     * GET /laundries/drivers
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');

        if (! $vendorId) {
            return errorResponse('Vendor ID is required', null, 400);
        }

        $vendor = Vendor::find($vendorId);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        // Get drivers associated with this vendor (via branches)
        $drivers = Driver::whereHas('branch', function ($q) use ($vendorId) {
            $q->where('vendor_id', $vendorId);
        })
            ->with('branch')
            ->paginate($request->input('per_page', 15));

        $driversData = $drivers->getCollection()->map(function ($driver) {
            $locale = app()->getLocale();

            return [
                'Driver_image' => $driver->image,
                'Driver_name' => $driver->getTranslation('full_name', $locale) ?? $driver->full_name,
                'Phone' => $driver->phone,
                'Email' => $driver->email,

                'Driver_status' => $driver->is_available ? 'active' : 'in_active',
                'Branch' => $driver->branch ? ($driver->branch->getTranslation('name', $locale) ?? $driver->branch->name) : 'N/A',
            ];
        });

        $drivers->setCollection($driversData);

        return successResponse($drivers, 'Drivers retrieved successfully');
    }

    /**
     * Get single driver
     * GET /laundries/drivers/:id
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        $driverData = [
            'Driver_image' => $driver->image,
            'Driver_name' => $driver->getTranslation('full_name', 'ar') ?? $driver->full_name,
            'Phone' => $driver->phone,

            'Email' => $driver->email,
            'Driver_status' => $driver->is_available ? 'active' : 'in_active',
            'Branch' => $driver->branch ? ($driver->branch->getTranslation('name', 'ar') ?? $driver->branch->name) : 'N/A',
        ];

        return successResponse($driverData, 'Driver retrieved successfully');
    }

    /**
     * Create driver
     * POST /laundries/drivers
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'Driver_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'Driver_name' => 'required|string',
            'Phone' => 'required|string|unique:drivers,phone',

            'Email' => 'required|email|unique:drivers,email',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $driverData = [
            'full_name' => ['ar' => $validated['Driver_name'], 'en' => $validated['Driver_name']],
            'phone' => $validated['Phone'],

            'email' => $validated['Email'],
            'branch_id' => $validated['branch_id'],
            'is_available' => true,
        ];

        // Handle image upload
        if ($request->hasFile('Driver_image')) {
            $driverData['image'] = $this->uploadFilesService->uploadImage(
                $request->file('Driver_image'),
                'drivers/images'
            );
        }

        $driver = Driver::create($driverData);

        return successResponse($this->formatDriver($driver), 'Driver created successfully', 201);
    }

    /**
     * Update driver
     * PUT /laundries/drivers/:id
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $driver = $this->driverService->find($id);

        if (! $driver) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        $validated = $request->validate([
            'Driver_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'Driver_name' => 'sometimes|string',
            'Phone' => 'sometimes|string|unique:drivers,phone,'.$id,

            'Email' => 'sometimes|email|unique:drivers,email,'.$id,
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $driverData = [];

        if (isset($validated['Driver_name'])) {
            $driverData['full_name'] = ['ar' => $validated['Driver_name'], 'en' => $validated['Driver_name']];
        }

        if (isset($validated['Phone'])) {
            $driverData['phone'] = $validated['Phone'];
        }

        if (isset($validated['Email'])) {
            $driverData['email'] = $validated['Email'];
        }

        if (isset($validated['branch_id'])) {
            $driverData['branch_id'] = $validated['branch_id'];
        }

        // Handle image upload
        if ($request->hasFile('Driver_image')) {
            $driverData['image'] = $this->uploadFilesService->uploadImage(
                $request->file('Driver_image'),
                'drivers/images',
                $driver->image
            );
        }

        $driver = $this->driverService->update($id, $driverData);

        return successResponse($this->formatDriver($driver), 'Driver updated successfully');
    }

    /**
     * Remove the specified driver.
     * DELETE /laundries/drivers/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->driverService->delete($id);

        if (! $deleted) {
            return ErrorResponse::make('Driver not found', null, 404);
        }

        return successResponse(null, 'Driver deleted successfully');
    }

    /**
     * Format driver data for response
     */
    private function formatDriver($driver): array
    {
        return [
            'Driver_image' => $driver->image,
            'Driver_name' => $driver->getTranslation('full_name', 'ar') ?? $driver->full_name,
            'Phone' => $driver->phone,

            'Email' => $driver->email,
            'Driver_status' => $driver->is_available ? 'active' : 'in_active',
            'Branch' => $driver->branch ? ($driver->branch->getTranslation('name', 'ar') ?? $driver->branch->name) : 'N/A',
        ];
    }
}
