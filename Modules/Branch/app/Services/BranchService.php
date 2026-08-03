<?php

namespace Modules\Branch\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Address\Models\Address;
use Modules\Branch\Interfaces\BranchRepositoryInterface;
use Modules\Branch\Models\Branch;
use Modules\Zone\Models\Zone;

class BranchService
{
    public function __construct(
        private BranchRepositoryInterface $branchRepository
    ) {}

    public function getAllBranches(array $filters = []): LengthAwarePaginator
    {
        return $this->branchRepository->all($filters);
    }

    public function getBranchById(int $id): ?Branch
    {
        return $this->branchRepository->find($id);
    }

    public function createBranch(array $data): Branch
    {
        return $this->branchRepository->create($data);
    }

    public function updateBranch(int $id, array $data): ?Branch
    {
        $branch = $this->branchRepository->find($id);

        if (! $branch) {
            return null;
        }

        $this->branchRepository->update($branch, $data);

        return $branch->fresh();
    }

    public function deleteBranch(int $id): bool
    {
        $branch = $this->branchRepository->find($id);

        if (! $branch) {
            return false;
        }

        return $this->branchRepository->delete($branch);
    }

    public function getStatistics(): array
    {
        return $this->branchRepository->getStatistics();
    }

    /**
     * Get branches available in a specific zone
     */
    public function getBranchesInZone(int $zoneId): Collection
    {
        return $this->branchRepository->getAvailableInZone($zoneId);
    }

    /**
     * Get branches available for a client's default address zone
     */
    public function getBranchesForClientZone(int $clientId): Collection
    {
        $defaultAddress = Address::where('client_id', $clientId)
            ->where('is_default', true)
            ->first();

        if (! $defaultAddress || ! $defaultAddress->zone_id) {
            return collect();
        }

        return $this->branchRepository->getAvailableInZone($defaultAddress->zone_id);
    }

    /**
     * Get branches in a zone that offer a specific service
     */
    public function getBranchesInZoneWithService(int $zoneId, int $serviceId): Collection
    {
        return $this->branchRepository->getAvailableInZoneWithService($zoneId, $serviceId);
    }

    /**
     * Get branches in a zone that offer specific services
     */
    public function getBranchesInZoneWithServices(int $zoneId, array $serviceIds): Collection
    {
        return $this->branchRepository->getAvailableInZoneWithServices($zoneId, $serviceIds);
    }

    /**
     * Get branches for client's default address zone with a specific service
     */
    public function getBranchesForClientWithService(int $clientId, int $serviceId): Collection
    {
        $defaultAddress = Address::where('client_id', $clientId)
            ->where('is_default', true)
            ->first();

        if (! $defaultAddress || ! $defaultAddress->zone_id) {
            return collect();
        }

        return $this->branchRepository->getAvailableInZoneWithService($defaultAddress->zone_id, $serviceId);
    }

    /**
     * Get branches for client's default address zone with specific services
     */
    public function getBranchesForClientWithServices(int $clientId, array $serviceIds): Collection
    {
        $defaultAddress = Address::where('client_id', $clientId)
            ->where('is_default', true)
            ->first();

        if (! $defaultAddress || ! $defaultAddress->zone_id) {
            return collect();
        }

        return $this->branchRepository->getAvailableInZoneWithServices($defaultAddress->zone_id, $serviceIds);
    }

    /**
     * Get branches by vendor
     */
    public function getBranchesByVendor(int $vendorId): Collection
    {
        return Branch::where('vendor_id', $vendorId)
            ->where('is_active', true)
            ->with(['zone', 'services', 'pieces'])
            ->get();
    }

    /**
     * Get branches with available drivers
     */
    public function getBranchesWithAvailableDrivers(int $zoneId): Collection
    {
        return Branch::where('zone_id', $zoneId)
            ->where('is_active', true)
            ->whereHas('vendor', function ($query) {
                $query->where('is_active', true)->where('is_banned', false);
            })
            ->withAvailableDrivers()
            ->with(['vendor', 'zone', 'availableDrivers'])
            ->get();
    }

    /**
     * Assign services to a branch from vendor's services
     */
    public function assignServicesToBranch(int $branchId, array $serviceIds): ?Branch
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        // Validate that services belong to the vendor
        $vendorServiceIds = $branch->vendor->services()->pluck('services.id')->toArray();
        $validServiceIds = array_intersect($serviceIds, $vendorServiceIds);

        $servicesData = [];
        foreach ($validServiceIds as $serviceId) {
            $servicesData[$serviceId] = [
                'price' => null, // Use vendor price by default
                'is_active' => true,
            ];
        }

        $branch->services()->sync($servicesData);

        // cache invalidation: branch assign services
        flushCacheTags(["branch_{$branchId}", 'services', 'branches']);

        return $branch->fresh(['services']);
    }

    /**
     * Assign pieces to a branch from vendor's pieces
     */
    public function assignPiecesToBranch(int $branchId, array $pieceIds): ?Branch
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        // Validate that pieces belong to the vendor
        $vendorPieceIds = $branch->vendor->pieces()->pluck('id')->toArray();
        $validPieceIds = array_intersect($pieceIds, $vendorPieceIds);

        $piecesData = [];
        foreach ($validPieceIds as $pieceId) {
            $piecesData[$pieceId] = [
                'is_active' => true,
            ];
        }

        $branch->pieces()->sync($piecesData);

        // cache invalidation: branch assign pieces
        flushCacheTags(["branch_{$branchId}", 'pieces', 'branches']);

        return $branch->fresh(['pieces']);
    }

    /**
     * Assign driver to a branch
     */
    public function assignDriverToBranch(int $branchId, int $driverId, bool $isPrimary = false): ?Branch
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        $branch->assignDriver($driverId, $isPrimary);

        return $branch->fresh(['drivers']);
    }

    /**
     * Remove driver from a branch
     */
    public function removeDriverFromBranch(int $branchId, int $driverId): ?Branch
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        $branch->removeDriver($driverId);

        return $branch->fresh(['drivers']);
    }

    /**
     * Update branch service price
     */
    public function updateBranchServicePrice(int $branchId, int $serviceId, ?float $price): ?Branch
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        $branch->services()->updateExistingPivot($serviceId, ['price' => $price]);

        // cache invalidation: branch service price update
        flushCacheTags(["branch_{$branchId}", 'services', 'branches']);

        return $branch->fresh(['services']);
    }

    /**
     * Toggle branch service active status
     */
    public function toggleBranchServiceStatus(int $branchId, int $serviceId): ?Branch
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        $service = $branch->services()->where('services.id', $serviceId)->first();

        if ($service) {
            $branch->services()->updateExistingPivot($serviceId, [
                'is_active' => ! $service->pivot->is_active,
            ]);

            // cache invalidation: branch service status toggle
            flushCacheTags(["branch_{$branchId}", 'services', 'branches']);
        }

        return $branch->fresh(['services']);
    }

    /**
     * Sync all vendor services to branch
     */
    public function syncAllVendorServicesToBranch(int $branchId): ?Branch
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        $branch->syncServicesFromVendor();

        return $branch->fresh(['services']);
    }

    /**
     * Sync all vendor pieces to branch
     */
    public function syncAllVendorPiecesToBranch(int $branchId): ?Branch
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        $branch->syncPiecesFromVendor();

        return $branch->fresh(['pieces']);
    }

    /**
     * Get available branches for an order based on client's address and required services
     */
    public function getAvailableBranchesForOrder(int $clientId, array $serviceIds = []): Collection
    {
        $defaultAddress = Address::where('client_id', $clientId)
            ->where('is_default', true)
            ->first();

        if (! $defaultAddress || ! $defaultAddress->zone_id) {
            return collect();
        }

        $query = Branch::where('zone_id', $defaultAddress->zone_id)
            ->where('is_active', true)
            ->whereHas('vendor', function ($q) {
                $q->where('is_active', true)->where('is_banned', false);
            });

        if (! empty($serviceIds)) {
            $query->whereHas('services', function ($q) use ($serviceIds) {
                $q->whereIn('services.id', $serviceIds)
                    ->where('services.is_active', true)
                    ->where('branch_service.is_active', true)
                    ->whereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('vendor_service')
                            ->whereColumn('vendor_service.vendor_id', 'branches.vendor_id')
                            ->whereColumn('vendor_service.service_id', 'services.id')
                            ->where('vendor_service.is_active', true);
                    });
            });
        }

        return $query->with(['vendor', 'zone', 'services', 'availableDrivers'])->get();
    }

    /**
     * Get all pieces available at a branch
     */
    public function getPiecesByBranch(int $branchId): ?array
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        $locale = app()->getLocale();

        $pieces = $branch->activePieces()
            ->with([
                'iconRelation',
                'services' => function ($query) use ($branchId) {
                    $query->where('services.is_active', true)
                        ->where('service_piece.branch_id', $branchId);
                },
            ])
            ->get();

        $branchServices = $branch->services()
            ->get()
            ->keyBy('id');

        $pivotMap = \Modules\Piece\Support\PieceBranchOffering::pivotMapForPieces(
            $branchId,
            $pieces->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        return [
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->getTranslation('name', $locale),
                'location' => $branch->location,
                'delivery_price_per_km' => (float) ($branch->vendor?->delivery_price_per_km ?? 0),
                'vendor' => [
                    'id' => $branch->vendor->id,
                    'name' => $branch->vendor->name,
                    'delivery_price_per_km' => (float) ($branch->vendor->delivery_price_per_km ?? 0),
                ],
            ],
            'pieces' => $pieces->map(function ($piece) use ($branchServices, $branchId, $locale, $pivotMap) {
                $pieceRow = \Modules\Piece\Support\PiecePricingFormatter::pieceWithServices(
                    $piece,
                    $branchId,
                    $locale,
                    $pivotMap[(int) $piece->id] ?? null,
                    true
                );
                $pieceRow['services'] = \Modules\Piece\Support\PiecePricingFormatter::mapServicesWithPrices(
                    $piece->services->filter(fn ($service) => $branchServices->has($service->id)),
                    $piece,
                    $branchId,
                    $locale,
                    true
                );
                $pieceRow['additional_services'] = \Modules\Piece\Support\PiecePricingFormatter::additionalServicesForPiece(
                    $piece,
                    $branchId,
                    $locale,
                    true
                );

                return $pieceRow;
            }),
        ];
    }

    /**
     * Get all services and additional services for a specific piece at a branch
     */
    public function getPieceServicesAtBranch(int $branchId, int $pieceId): ?array
    {
        $branch = $this->getBranchById($branchId);

        if (! $branch) {
            return null;
        }

        // Verify the piece is available at this branch
        $pieceAtBranch = $branch->pieces()->where('pieces.id', $pieceId)->first();

        if (! $pieceAtBranch) {
            return null;
        }

        // Use PieceService to get services with branch filtering
        $pieceService = app(\Modules\Piece\Services\PieceService::class);

        return $pieceService->getServicesByPiece($pieceId, $branchId);
    }
}
