<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Requests\UpdateBranchRequest;
use Modules\Admin\Http\Resources\BranchResource;
use Modules\Branch\Models\Branch;

class AdminBranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Branch::with(['vendor']);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('zone_id')) {
            $query->where('zone_id', $request->zone_id);
        }

        if ($request->filled('zone_name')) {
            $zoneName = $request->zone_name;
            $query->whereHas('zone', function ($q) use ($zoneName) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) = ?", [$zoneName])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) = ?", [$zoneName]);
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereRaw("JSON_EXTRACT(name, '$$.ar') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("JSON_EXTRACT(name, '$$.en') LIKE ?", ["%{$search}%"])
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $branches = $query->paginate($request->input('per_page', 15));

        return successResponse(
            BranchResource::collection($branches),
            'Branches retrieved successfully'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            // accept translated name as array (title[ar], title[en]) or string
            'name' => 'required_without:name',
            'name' => 'required_without:name',
            'phone' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'address' => 'nullable',
            'location' => 'nullable',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        // Normalize name (accept `name` or `name`)
        if (isset($validated['name'])) {
            $branchName = $validated['name'];
        } else {
            $branchName = $request->input('name');
        }

        if ($branchName !== null) {
            if (is_string($branchName)) {
                $validated['name'] = $branchName;
            } elseif (is_array($branchName)) {
                $validated['name'] = $branchName;
            }
        }

        // Normalize phone
        if (isset($validated['phone_number'])) {
            $validated['phone_number'] = $validated['phone_number'];
        } elseif (isset($validated['phone'])) {
            $validated['phone_number'] = $validated['phone'];
        }

        // Normalize location (accept address or location)
        if (isset($validated['location'])) {
            $validated['location'] = $validated['location'];
        } elseif (isset($validated['address'])) {
            $validated['location'] = $validated['address'];
        }

        $branch = Branch::create($validated);

        return successResponse(
            $branch->load(['vendor']),
            'Branch created successfully',
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $branch = Branch::with(['vendor'])->find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        return successResponse(
            $branch,
            'Branch retrieved successfully'
        );
    }

    public function update(UpdateBranchRequest $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $validated = $request->validated();

        if (isset($validated['name']) || $request->has('name')) {
            $branchName = $validated['name'] ?? $request->input('name');
            if (is_string($branchName) || is_array($branchName)) {
                $validated['name'] = $branchName;
            }
        }

        if (isset($validated['phone_number'])) {
            // keep
        } elseif (isset($validated['phone'])) {
            $validated['phone_number'] = $validated['phone'];
        }

        if (isset($validated['location']) || $request->has('address')) {
            $validated['location'] = $validated['location'] ?? $request->input('address');
        }

        $branch->update($validated);

        return successResponse(
            $branch->fresh()->load(['vendor']),
            'Branch updated successfully'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $branch->delete();

        return successResponse(
            null,
            'Branch deleted successfully'
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $branch->is_active = ! $branch->is_active;
        $branch->save();

        return successResponse(
            $branch,
            'Branch status updated successfully'
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_branches' => Branch::count(),
            'active_branches' => Branch::where('is_active', true)->count(),
            'inactive_branches' => Branch::where('is_active', false)->count(),
        ];

        return successResponse(
            $stats,
            'Branch statistics retrieved successfully'
        );
    }

    /**
     * Get pieces assigned to a branch
     */
    public function getPieces(int $id): JsonResponse
    {
        $branch = Branch::with(['pieces' => function ($query) {
            $query->orderBy('order', 'asc');
        }])->find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        return successResponse([
            'branch_id' => $branch->id,
            'pieces' => $branch->pieces,
        ], 'Branch pieces retrieved successfully');
    }

    /**
     * Add pieces to a branch
     */
    public function addPieces(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $validated = $request->validate([
            'piece_ids' => 'required|array|min:1',
            'piece_ids.*' => 'exists:pieces,id',
        ]);

        $branch->pieces()->syncWithoutDetaching($validated['piece_ids']);

        // cache invalidation
        flushCacheTags(["branch_{$id}", 'pieces', 'branches']);

        return successResponse([
            'branch_id' => $branch->id,
            'pieces' => $branch->pieces()->orderBy('order', 'asc')->get(),
        ], 'Pieces added to branch successfully');
    }

    /**
     * Remove pieces from a branch
     */
    public function removePieces(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $validated = $request->validate([
            'piece_ids' => 'required|array|min:1',
            'piece_ids.*' => 'exists:pieces,id',
        ]);

        $branch->pieces()->detach($validated['piece_ids']);

        // cache invalidation
        flushCacheTags(["branch_{$id}", 'pieces', 'branches']);

        return successResponse([
            'branch_id' => $branch->id,
            'pieces' => $branch->pieces()->orderBy('order', 'asc')->get(),
        ], 'Pieces removed from branch successfully');
    }

    public function syncPieces(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $validated = $request->validate([
            'piece_ids' => 'required|array',
            'piece_ids.*' => 'exists:pieces,id',
        ]);

        $branch->pieces()->sync($validated['piece_ids']);

        // cache invalidation
        flushCacheTags(["branch_{$id}", 'pieces', 'branches']);

        return successResponse([
            'branch_id' => $branch->id,
            'pieces' => $branch->pieces()->orderBy('order', 'asc')->get(),
        ], 'Branch pieces synced successfully');
    }

    /**
     * Toggle piece status in a branch (deprecated - is_active removed)
     */
    public function togglePieceStatus(int $branchId, int $pieceId): JsonResponse
    {
        $branch = Branch::find($branchId);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $piece = $branch->pieces()->where('piece_id', $pieceId)->first();

        if (! $piece) {
            return notFoundResponse('Piece not found in this branch');
        }

        return successResponse([
            'branch_id' => $branch->id,
            'piece_id' => $pieceId,
            'message' => 'is_active column has been removed from pieces',
        ], 'Piece status toggle is no longer supported');
    }
}
