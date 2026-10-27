<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            __('admin.branches_retrieved')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            // accept translated name as array (title[ar], title[en]) or string
            'name' => 'required_without:name',
            'name.ar' => [
                'sometimes', 'string',
                Rule::unique('branches', 'name->ar')->where(fn ($q) => $q->where('vendor_id', $request->input('vendor_id')))->whereNull('deleted_at'),
            ],
            'name.en' => [
                'sometimes', 'string',
                Rule::unique('branches', 'name->en')->where(fn ($q) => $q->where('vendor_id', $request->input('vendor_id')))->whereNull('deleted_at'),
            ],
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
        unset($validated['phone']);

        // Normalize location (accept address or location)
        if (isset($validated['location'])) {
            $validated['location'] = $validated['location'];
        } elseif (isset($validated['address'])) {
            $validated['location'] = $validated['address'];
        }

        $branch = Branch::create($validated);

        return successResponse(
            $branch->load(['vendor']),
            __('admin.branch_created'),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $branch = Branch::with(['vendor'])->find($id);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
        }

        return successResponse(
            $branch,
            __('admin.branch_retrieved')
        );
    }

    public function update(UpdateBranchRequest $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
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
        unset($validated['phone']);

        if (isset($validated['location']) || $request->has('address')) {
            $validated['location'] = $validated['location'] ?? $request->input('address');
        }

        $branch->update($validated);

        return successResponse(
            $branch->fresh()->load(['vendor']),
            __('admin.branch_updated')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
        }

        $branch->delete();

        return successResponse(
            null,
            __('admin.branch_deleted')
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
        }

        $branch->is_active = ! $branch->is_active;
        $branch->save();

        return successResponse(
            $branch,
            __('admin.branch_status_updated')
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
            __('admin.branch_statistics_retrieved')
        );
    }

    /**
     * Get pieces assigned to a branch
     */
    public function getPieces(int $id): JsonResponse
    {
        $branch = Branch::with(['pieces' => function ($query) {
            $query->orderBy('pieces.id', 'asc');
        }])->find($id);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
        }

        return successResponse([
            'branch_id' => $branch->id,
            'pieces' => $this->withAdditionalServices($branch->pieces, $id),
        ], __('admin.branch_pieces_retrieved'));
    }

    /**
     * Attach each piece's additional services for this branch (service_addition_piece,
     * scoped to $branchId) — the pieces() relation itself only carries the branch_piece
     * pivot (name/is_active), so without this the dashboard's "pieces in branch" screen
     * has no way to show which additional services are linked to each piece there.
     */
    private function withAdditionalServices($pieces, int $branchId)
    {
        $locale = app()->getLocale();

        return $pieces->each(function ($piece) use ($branchId, $locale) {
            $piece->additional_services = $piece->additionalServicesAtBranch($branchId)
                ->get()
                ->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->getTranslation('name', $locale),
                    'icon_id' => $service->icon_id,
                    'price' => (float) ($service->pivot->price ?? 0),
                ])
                ->values();
        });
    }

    /**
     * Add pieces to a branch
     */
    public function addPieces(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
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
            'pieces' => $this->withAdditionalServices($branch->pieces()->orderBy('pieces.id', 'asc')->get(), $id),
        ], __('admin.pieces_added_to_branch'));
    }

    /**
     * Remove pieces from a branch
     */
    public function removePieces(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
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
            'pieces' => $this->withAdditionalServices($branch->pieces()->orderBy('pieces.id', 'asc')->get(), $id),
        ], __('admin.pieces_removed_from_branch'));
    }

    public function syncPieces(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
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
            'pieces' => $this->withAdditionalServices($branch->pieces()->orderBy('pieces.id', 'asc')->get(), $id),
        ], __('admin.branch_pieces_synced'));
    }

    /**
     * Toggle piece status in a branch (deprecated - is_active removed)
     */
    public function togglePieceStatus(int $branchId, int $pieceId): JsonResponse
    {
        $branch = Branch::find($branchId);

        if (! $branch) {
            return notFoundResponse(__('admin.branch_not_found'));
        }

        $piece = $branch->pieces()->where('piece_id', $pieceId)->first();

        if (! $piece) {
            return notFoundResponse(__('admin.piece_not_found_in_branch'));
        }

        return successResponse([
            'branch_id' => $branch->id,
            'piece_id' => $pieceId,
            'message' => 'is_active column has been removed from pieces',
        ], __('admin.piece_status_toggle_unsupported'));
    }
}
