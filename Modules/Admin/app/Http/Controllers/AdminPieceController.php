<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Resources\PieceResource;
use Modules\Piece\Models\Piece;

class AdminPieceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Piece::with(['vendor', 'branches']);

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $pieces = $query->paginate($request->input('per_page', 15));

        return successResponse(
            PieceResource::collection($pieces),
            'Pieces retrieved successfully'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'name' => 'required|array',
            'name.ar' => 'required|string|max:255',
            'name.en' => 'required|string|max:255',
            'description' => 'nullable|array',
            'description.ar' => 'nullable|string',
            'description.en' => 'nullable|string',
            'icon_id' => 'required|exists:icons,id',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
            'service_ids' => 'nullable|array',
            'service_ids.*.service_id' => 'required_with:service_ids|exists:services,id',
            'service_ids.*.price' => 'required_with:service_ids|numeric|min:0',
        ]);

        // Prepare data for piece creation
        $pieceData = [
            'vendor_id' => $validated['vendor_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'icon_id' => $validated['icon_id'],
            'order' => $validated['order'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ];

        $piece = Piece::create($pieceData);

        // Attach to branches if provided
        $branchIds = $validated['branch_ids'] ?? [];
        if (! empty($branchIds)) {
            $piece->branches()->attach($branchIds, ['is_active' => true]);
        }

        // Attach to services if provided
        $serviceIds = $validated['service_ids'] ?? [];
        if (! empty($serviceIds)) {
            $servicePivotData = [];
            foreach ($serviceIds as $serviceData) {
                $servicePivotData[$serviceData['service_id']] = [
                    'price' => $serviceData['price'],
                ];
            }
            $piece->services()->attach($servicePivotData);
        }

        // cache invalidation: piece created with associations
        flushCacheTags(['pieces', 'branches', 'services']);

        return successResponse(
            new PieceResource($piece->load(['branches', 'services', 'iconRelation'])),
            'Piece created successfully',
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $piece = Piece::with(['vendor', 'branches', 'services'])->find($id);

        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        return successResponse(
            $piece,
            'Piece retrieved successfully'
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $piece = Piece::find($id);

        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        $validated = $request->validate([
            'vendor_id' => 'sometimes|exists:vendors,id',
            'name' => 'sometimes|array',
            'name.ar' => 'required_with:name|string|max:255',
            'name.en' => 'required_with:name|string|max:255',
            'description' => 'nullable|array',
            'description.ar' => 'nullable|string',
            'description.en' => 'nullable|string',
            'icon_id' => 'sometimes|required|exists:icons,id',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
            'service_ids' => 'nullable|array',
            'service_ids.*.service_id' => 'required_with:service_ids|exists:services,id',
            'service_ids.*.price' => 'required_with:service_ids|numeric|min:0',
        ]);

        // Extract fields that need special handling
        $branchIds = $validated['branch_ids'] ?? null;
        $serviceIds = $validated['service_ids'] ?? null;

        // Remove fields that shouldn't be in the update array
        unset($validated['branch_ids'], $validated['service_ids']);

        $piece->update($validated);

        // Sync branches if provided
        if ($branchIds !== null) {
            $piece->branches()->sync(
                collect($branchIds)->mapWithKeys(fn ($id) => [$id => ['is_active' => true]])->toArray()
            );
        }

        // Sync services if provided
        if ($serviceIds !== null) {
            $servicePivotData = [];
            foreach ($serviceIds as $serviceData) {
                $servicePivotData[$serviceData['service_id']] = [
                    'price' => $serviceData['price'],
                ];
            }
            $piece->services()->sync($servicePivotData);
        }

        // cache invalidation: piece updated with associations
        flushCacheTags(['pieces', 'branches', 'services']);

        return successResponse(
            new PieceResource($piece->fresh()->load(['branches', 'services', 'iconRelation'])),
            'Piece updated successfully'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $piece = Piece::find($id);

        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        $piece->delete();

        return successResponse(
            null,
            'Piece deleted successfully'
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $piece = Piece::find($id);

        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        // is_active column has been removed from pieces table
        return successResponse(
            $piece,
            'Piece status toggle is no longer supported'
        );

        return successResponse(
            $piece,
            'Piece status updated successfully'
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_pieces' => Piece::count(),
            'active_pieces' => Piece::where('is_active', true)->count(),
            'inactive_pieces' => Piece::where('is_active', false)->count(),
        ];

        return successResponse(
            $stats,
            'Piece statistics retrieved successfully'
        );
    }
}
