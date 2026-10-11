<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Address\Models\Address;
use Modules\Admin\Http\Resources\AddressResource;

class AdminAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Address::with(['client']);

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('street_name', 'like', "%{$search}%")
                    ->orWhere('building_number', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $addresses = $query->paginate($request->input('per_page', 15));

        return successResponse(
            AddressResource::collection($addresses),
            __('admin.addresses_retrieved')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'title' => 'required|string|max:255',
            'street_name' => 'required|string',
            'building_number' => 'nullable|string',
            'floor_number' => 'nullable|string',
            'apartment_number' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_default' => 'boolean',
        ]);

        $address = Address::create($validated);

        return successResponse(
            $address->load(['client']),
            __('admin.address_created'),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $address = Address::with(['client'])->find($id);

        if (! $address) {
            return notFoundResponse(__('admin.address_not_found'));
        }

        return successResponse(
            $address,
            __('admin.address_retrieved')
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $address = Address::find($id);

        if (! $address) {
            return notFoundResponse(__('admin.address_not_found'));
        }

        $validated = $request->validate([
            'client_id' => 'sometimes|exists:clients,id',
            'title' => 'sometimes|string|max:255',
            'street_name' => 'sometimes|string',
            'building_number' => 'nullable|string',
            'floor_number' => 'nullable|string',
            'apartment_number' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_default' => 'boolean',
        ]);

        $address->update($validated);

        return successResponse(
            $address->fresh()->load(['client']),
            __('admin.address_updated')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $address = Address::find($id);

        if (! $address) {
            return notFoundResponse(__('admin.address_not_found'));
        }

        $address->delete();

        return successResponse(
            null,
            __('admin.address_deleted')
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_addresses' => Address::count(),
            'default_addresses' => Address::where('is_default', true)->count(),
            'addresses_per_client' => Address::selectRaw('client_id, COUNT(*) as count')
                ->groupBy('client_id')
                ->avg('count'),
        ];

        return successResponse(
            $stats,
            __('admin.address_statistics_retrieved')
        );
    }
}
