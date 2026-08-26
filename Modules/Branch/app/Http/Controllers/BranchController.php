<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Branch\Http\Requests\StoreBranchRequest;
use Modules\Branch\Http\Requests\UpdateBranchRequest;
use Modules\Branch\Http\Resources\BranchResource;
use Modules\Branch\Services\BranchService;

class BranchController extends Controller
{
    public function __construct(private BranchService $branchService) {}

    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');

        $filters = [
            'vendor_id' => $vendorId,
            'zone_id' => $request->input('zone_id'),
            'zone_ids' => $request->input('zone_ids'),
            'zone_name' => $request->input('zone_name'),
            'is_active' => $request->input('is_active'),
            'search' => $request->input('search'),
            'per_page' => $request->input('per_page', 15),
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $branches = $this->branchService->getAllBranches($filters);

        $stats = null;
        if ($vendorId) {
            $locale = app()->getLocale();

            // Total branches
            $totalBranches = \Modules\Branch\Models\Branch::where('vendor_id', $vendorId)->count();

            // Branch with highest rating
            $highestRatingBranch = \Modules\Branch\Models\Branch::where('vendor_id', $vendorId)
                ->orderByDesc('rating')->first();

            // Branch with highest revenue
            $highestRevenueBranchData = \Illuminate\Support\Facades\DB::table('branches')
                ->join('orders', 'branches.id', '=', 'orders.branch_id')
                ->where('branches.vendor_id', $vendorId)
                ->where('orders.status', 'completed')
                ->select('branches.name', 'branches.id', \Illuminate\Support\Facades\DB::raw('SUM(orders.final_amount) as total_revenue'))
                ->groupBy('branches.id', 'branches.name')
                ->orderByDesc('total_revenue')
                ->first();

            // Branch with highest orders
            $highestOrdersBranchData = \Illuminate\Support\Facades\DB::table('branches')
                ->join('orders', 'branches.id', '=', 'orders.branch_id')
                ->where('branches.vendor_id', $vendorId)
                ->select('branches.name', 'branches.id', \Illuminate\Support\Facades\DB::raw('COUNT(orders.id) as total_orders'))
                ->groupBy('branches.id', 'branches.name')
                ->orderByDesc('total_orders')
                ->first();

            $formatName = function ($nameParam) use ($locale) {
                if (! $nameParam) {
                    return null;
                }
                $decoded = json_decode($nameParam, true);
                if (is_array($decoded)) {
                    return $decoded[$locale] ?? $decoded['en'] ?? $decoded['ar'] ?? $nameParam;
                }

                return $nameParam;
            };

            $stats = [
                'highest_rating' => [
                    'name' => $highestRatingBranch ? $highestRatingBranch->getTranslation('name', $locale) : null,
                    'value' => $highestRatingBranch ? (float) $highestRatingBranch->rating : 0,
                ],
                'highest_revenue' => [
                    'name' => $highestRevenueBranchData ? $formatName($highestRevenueBranchData->name) : null,
                    'value' => $highestRevenueBranchData ? (float) $highestRevenueBranchData->total_revenue : 0,
                ],
                'highest_orders' => [
                    'name' => $highestOrdersBranchData ? $formatName($highestOrdersBranchData->name) : null,
                    'value' => $highestOrdersBranchData ? (int) $highestOrdersBranchData->total_orders : 0,
                ],
                'total_branches' => $totalBranches,
            ];
        }

        $paginatedData = BranchResource::collection($branches)->response()->getData(true);

        $meta = [
            'current_page' => $paginatedData['meta']['current_page'] ?? null,
            'from' => $paginatedData['meta']['from'] ?? null,
            'last_page' => $paginatedData['meta']['last_page'] ?? null,
            'per_page' => $paginatedData['meta']['per_page'] ?? null,
            'to' => $paginatedData['meta']['to'] ?? null,
            'total' => $paginatedData['meta']['total'] ?? null,
        ];

        return jsonResponse(
            true,
            200,
            'Branches retrieved successfully',
            [
                'statistics' => $stats,
                'branches' => $paginatedData['data'],
            ],
            $meta
        );
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = $this->branchService->createBranch($request->validated());

        return successResponse(new BranchResource($branch), 'Branch created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $branch = $this->branchService->getBranchById($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        return successResponse(new BranchResource($branch), 'Branch retrieved successfully');
    }

    public function update(UpdateBranchRequest $request, int $id): JsonResponse
    {
        $branch = $this->branchService->updateBranch($id, $request->validated());
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        return successResponse(new BranchResource($branch), 'Branch updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->branchService->deleteBranch($id);
        if (! $deleted) {
            return notFoundResponse('Branch not found');
        }

        return successResponse(null, 'Branch deleted successfully');
    }

    /**
     * Get all pieces available at this branch
     */
    public function getPieces(int $id): JsonResponse
    {
        $pieces = $this->branchService->getPiecesByBranch($id);
        if ($pieces === null) {
            return notFoundResponse('Branch not found');
        }

        return successResponse($pieces, 'Pieces retrieved successfully');
    }

    /**
     * Get all services for a specific piece at this branch
     */
    public function getPieceServices(int $branch_id, int $piece_id): JsonResponse
    {
        $services = $this->branchService->getPieceServicesAtBranch($branch_id, $piece_id);

        if ($services === null) {
            return notFoundResponse('Branch or piece not found, or piece not available at this branch');
        }

        return successResponse($services, 'Services retrieved successfully');
    }
}
