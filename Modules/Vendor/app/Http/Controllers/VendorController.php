<?php

namespace Modules\Vendor\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Modules\Vendor\Http\Requests\StoreVendorRequest;
use Modules\Vendor\Http\Requests\UpdateVendorRequest;
use Modules\Vendor\Http\Resources\VendorResource;
use Modules\Vendor\Services\VendorService;

class VendorController extends Controller
{
    public function __construct(
        private VendorService $vendorService,
        private UploadFilesService $uploadFilesService
    ) {}

    public function index(): JsonResponse
    {
        $vendors = $this->vendorService->getAllVendors();

        return successResponse(
            VendorResource::collection($vendors),
            'Vendors retrieved successfully'
        );
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $validated['logo'] = $this->uploadFilesService->uploadLogo(
                $request->file('logo'),
                'vendors/logos'
            );
        }

        $vendor = $this->vendorService->createVendor($validated);

        return successResponse(new VendorResource($vendor), 'Vendor created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $vendor = $this->vendorService->getVendorById($id);
        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        return successResponse(new VendorResource($vendor), 'Vendor retrieved successfully');
    }

    public function update(UpdateVendorRequest $request, int $id): JsonResponse
    {
        $vendor = $this->vendorService->updateVendor($id, $request->validated());
        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        return successResponse(new VendorResource($vendor), 'Vendor updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->vendorService->deleteVendor($id);
        if (! $deleted) {
            return notFoundResponse('Vendor not found');
        }

        return successResponse(null, 'Vendor deleted successfully');
    }
}
