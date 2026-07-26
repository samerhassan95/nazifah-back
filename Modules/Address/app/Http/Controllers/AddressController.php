<?php

namespace Modules\Address\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Address\Http\Requests\StoreAddressRequest;
use Modules\Address\Http\Requests\UpdateAddressRequest;
use Modules\Address\Http\Resources\AddressResource;
use Modules\Address\Services\AddressService;

class AddressController extends Controller
{
    public function __construct(private AddressService $addressService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = ['client_id' => $request->user()->id];
        $addresses = $this->addressService->getAllAddresses($filters);

        return successResponse(
            AddressResource::collection($addresses),
            'Addresses retrieved successfully'
        );
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = $this->addressService->createAddress($request->validated());

        return successResponse(new AddressResource($address), 'Address created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $address = $this->addressService->getAddressById($id);
        if (! $address) {
            return notFoundResponse('Address not found');
        }

        return successResponse(new AddressResource($address), 'Address retrieved successfully');
    }

    public function update(UpdateAddressRequest $request, int $id): JsonResponse
    {
        $address = $this->addressService->updateAddress($id, $request->validated());
        if (! $address) {
            return notFoundResponse('Address not found');
        }

        return successResponse(new AddressResource($address), 'Address updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->addressService->deleteAddress($id);
        if (! $deleted) {
            return notFoundResponse('Address not found');
        }

        return successResponse(null, 'Address deleted successfully');
    }
}
