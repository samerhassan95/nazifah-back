<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use Modules\Service\Support\ServiceAdditionBranchOffering;
use Modules\Vendor\Models\Vendor;

class CatalogToggleController extends Controller
{
    /**
     * Toggle catalog service (services.is_active).
     * POST /api/v1/vendor/services/{serviceId}/toggle-active
     */
    public function toggleService(Request $request, int $serviceId): JsonResponse
    {
        $vendorId = (int) $request->user()->vendor_id;
        $vendor = Vendor::find($vendorId);

        if (! $vendor) {
            return notFoundResponse(__('vendor.vendor_not_found'));
        }

        $service = Service::find($serviceId);
        if (! $service) {
            return notFoundResponse(__('branch.service_not_found'));
        }

        if (! $vendor->catalogServices()->where('services.id', $serviceId)->exists()) {
            return notFoundResponse(__('service.service_not_in_vendor_catalog'));
        }

        $service->update(['is_active' => ! $service->is_active]);

        $this->clearCatalogCache($serviceId);

        return successResponse([
            'service_id' => $service->id,
            'is_active' => (bool) $service->is_active,
        ], __('service.service_status_updated'));
    }

    /**
     * Toggle service at branch (branch_service.is_active).
     * POST /api/v1/vendor/branches/{branchId}/services/{serviceId}/toggle-active
     */
    public function toggleBranchService(Request $request, int $branchId, int $serviceId): JsonResponse
    {
        $branch = $this->resolveVendorBranch($request, $branchId);
        if ($branch instanceof JsonResponse) {
            return $branch;
        }

        $pivot = DB::table('branch_service')
            ->where('branch_id', $branchId)
            ->where('service_id', $serviceId)
            ->first();

        if (! $pivot) {
            return notFoundResponse(__('service.service_not_available_at_branch'));
        }

        $isActive = ! ((bool) ($pivot->is_active ?? true));
        DB::table('branch_service')
            ->where('branch_id', $branchId)
            ->where('service_id', $serviceId)
            ->update(['is_active' => $isActive, 'updated_at' => now()]);

        $this->clearCatalogCache($serviceId, $branchId);

        return successResponse([
            'branch_id' => $branchId,
            'service_id' => $serviceId,
            'is_active' => $isActive,
        ], __('service.branch_service_status_updated'));
    }

    /**
     * Toggle catalog piece (pieces.is_active).
     * POST /api/v1/vendor/pieces/{pieceId}/toggle-active
     */
    public function togglePiece(Request $request, int $pieceId): JsonResponse
    {
        $vendorId = (int) $request->user()->vendor_id;

        $piece = Piece::where('id', $pieceId)->where('vendor_id', $vendorId)->first();
        if (! $piece) {
            return notFoundResponse(__('piece.piece_not_found'));
        }

        $piece->update(['is_active' => ! $piece->is_active]);

        $this->clearCatalogCache(null, null, $pieceId);

        return successResponse([
            'piece_id' => $piece->id,
            'is_active' => (bool) $piece->is_active,
        ], __('piece.piece_status_updated'));
    }

    /**
     * Toggle piece at branch (branch_piece.is_active).
     * POST /api/v1/vendor/branches/{branchId}/pieces/{pieceId}/toggle-active
     */
    public function toggleBranchPiece(Request $request, int $branchId, int $pieceId): JsonResponse
    {
        $branch = $this->resolveVendorBranch($request, $branchId);
        if ($branch instanceof JsonResponse) {
            return $branch;
        }

        $vendorId = (int) $request->user()->vendor_id;
        $piece = Piece::where('id', $pieceId)->where('vendor_id', $vendorId)->first();
        if (! $piece) {
            return notFoundResponse(__('piece.piece_not_found'));
        }

        $pivot = DB::table('branch_piece')
            ->where('branch_id', $branchId)
            ->where('piece_id', $pieceId)
            ->first();

        if (! $pivot) {
            return notFoundResponse(__('piece.piece_not_available_at_branch'));
        }

        $isActive = ! ((bool) ($pivot->is_active ?? true));
        DB::table('branch_piece')
            ->where('branch_id', $branchId)
            ->where('piece_id', $pieceId)
            ->update(['is_active' => $isActive, 'updated_at' => now()]);

        $this->clearCatalogCache(null, $branchId, $pieceId);

        return successResponse([
            'branch_id' => $branchId,
            'piece_id' => $pieceId,
            'is_active' => $isActive,
        ], __('piece.branch_piece_status_updated'));
    }

    /**
     * Toggle catalog service addition (service_additions.is_active).
     * POST /api/v1/vendor/additional-services/{serviceAdditionId}/toggle-active
     */
    public function toggleServiceAddition(Request $request, int $serviceAdditionId): JsonResponse
    {
        $vendorId = (int) $request->user()->vendor_id;

        $addition = ServiceAddition::where('id', $serviceAdditionId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $addition) {
            return notFoundResponse(__('service.service_addition_not_found'));
        }

        $addition->update(['is_active' => ! $addition->is_active]);

        $this->clearCatalogCache();

        return successResponse([
            'service_addition_id' => $addition->id,
            'is_active' => (bool) $addition->is_active,
        ], __('service.service_addition_status_updated'));
    }

    /**
     * Toggle service addition at branch (branch_service_addition.is_active).
     * POST /api/v1/vendor/branches/{branchId}/additional-services/{serviceAdditionId}/toggle-active
     */
    public function toggleBranchServiceAddition(Request $request, int $branchId, int $serviceAdditionId): JsonResponse
    {
        $branch = $this->resolveVendorBranch($request, $branchId);
        if ($branch instanceof JsonResponse) {
            return $branch;
        }

        $vendorId = (int) $request->user()->vendor_id;
        $addition = ServiceAddition::where('id', $serviceAdditionId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $addition) {
            return notFoundResponse(__('service.service_addition_not_found'));
        }

        $linkedAtBranch = DB::table('branch_service_addition')
            ->where('branch_id', $branchId)
            ->where('service_addition_id', $serviceAdditionId)
            ->exists()
            || DB::table('service_addition_piece')
                ->where('branch_id', $branchId)
                ->where('service_addition_id', $serviceAdditionId)
                ->exists();

        if (! $linkedAtBranch) {
            return notFoundResponse(__('service.service_addition_not_on_branch'));
        }

        $pivot = DB::table('branch_service_addition')
            ->where('branch_id', $branchId)
            ->where('service_addition_id', $serviceAdditionId)
            ->first();

        if (! $pivot) {
            ServiceAdditionBranchOffering::upsert($branchId, $addition, [
                'price' => (float) $addition->price,
            ]);
            $isActive = false;
            DB::table('branch_service_addition')
                ->where('branch_id', $branchId)
                ->where('service_addition_id', $serviceAdditionId)
                ->update(['is_active' => $isActive, 'updated_at' => now()]);
        } else {
            $isActive = ! ((bool) ($pivot->is_active ?? true));
            DB::table('branch_service_addition')
                ->where('branch_id', $branchId)
                ->where('service_addition_id', $serviceAdditionId)
                ->update(['is_active' => $isActive, 'updated_at' => now()]);
        }

        $this->clearCatalogCache(null, $branchId);

        return successResponse([
            'branch_id' => $branchId,
            'service_addition_id' => $serviceAdditionId,
            'is_active' => $isActive,
        ], __('service.branch_service_addition_status_updated'));
    }

    private function resolveVendorBranch(Request $request, int $branchId): Branch|JsonResponse
    {
        $vendorId = (int) $request->user()->vendor_id;

        $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        return $branch;
    }

    private function clearCatalogCache(?int $serviceId = null, ?int $branchId = null, ?int $pieceId = null): void
    {
        $tags = ['services', 'branches', 'pieces', 'categories'];

        if ($branchId !== null) {
            $tags[] = "branch_{$branchId}";
        }
        if ($serviceId !== null) {
            $tags[] = "service_{$serviceId}";
        }
        if ($pieceId !== null) {
            $tags[] = "piece_{$pieceId}";
        }

        flushCacheTags(array_unique($tags));
    }
}
