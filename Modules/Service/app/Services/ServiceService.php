<?php

namespace Modules\Service\Services;

use App\Support\CatalogActiveFilter;
use App\Support\CatalogActivePresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Piece\Models\Piece;
use Modules\Piece\Support\PiecePricingFormatter;
use Modules\Service\Interfaces\ServiceRepositoryInterface;
use Modules\Service\Models\Service;

class ServiceService
{
    public function __construct(
        private ServiceRepositoryInterface $serviceRepository
    ) {}

    public function getAllServices(array $filters = []): LengthAwarePaginator
    {
        return $this->serviceRepository->all($filters);
    }

    public function getServiceById(int $id): ?Service
    {
        return $this->serviceRepository->find($id);
    }

    public function createService(array $data): Service
    {
        return $this->serviceRepository->create($data);
    }

    public function updateService(int $id, array $data): ?Service
    {
        $service = $this->serviceRepository->find($id);

        if (! $service) {
            return null;
        }

        $this->serviceRepository->update($service, $data);

        return $service->fresh();
    }

    public function deleteService(int $id): bool
    {
        $service = $this->serviceRepository->find($id);

        if (! $service) {
            return false;
        }

        return $this->serviceRepository->delete($service);
    }

    /**
     * Get all branches that offer this service (optionally filtered by zone)
     *
     * @param  int|null  $zoneId  When provided, only branches in this zone are returned
     */
    public function getBranchesByService(int $serviceId, ?int $zoneId = null): array
    {
        $service = $this->serviceRepository->find($serviceId);

        if (! $service) {
            return [
                'service' => null,
                'branches' => [],
            ];
        }

        $query = \Modules\Branch\Models\Branch::query()
            ->where('is_active', true)
            ->whereHas('vendor', function ($query) {
                $query->where('is_active', true)
                    ->where(function ($q) {
                        $q->where('is_banned', false)->orWhereNull('is_banned');
                    });
            })
            ->whereHas('services', function ($query) use ($serviceId) {
                $query->where('services.id', $serviceId);
            });

        if ($zoneId !== null) {
            $query->where('zone_id', $zoneId);
        }

        $branches = $query
            ->with([
                'vendor',
                'zone',
            ])
            ->get();

        return [
            'service' => [
                'id' => $service->id,
                'service_name' => $service->service_name,
                'description' => $service->description,
            ],
            'branches' => $branches->map(function ($branch) use ($serviceId) {
                $uploadService = app(\App\Services\UploadFilesService::class);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'location' => $branch->location,
                    'phone_number' => $branch->phone_number,
                    'latitude' => $branch->latitude,
                    'longitude' => $branch->longitude,
                    'rating' => $branch->rating,
                    'rate_count' => $branch->rate_count,
                    'image_cover' => $uploadService->getFullUrl($branch->store_front),
                    'image_logo' => $uploadService->getFullUrl($branch->logo),
                    'service_id' => $serviceId,
                    'service_price' => $branch->getServicePrice($serviceId),
                    'vendor' => [
                        'id' => $branch->vendor->id,
                        'name' => $branch->vendor->name,
                        'delivery_price_per_km' => (float) ($branch->vendor->delivery_price_per_km ?? 0),
                    ],
                    'delivery_price_per_km' => (float) ($branch->vendor?->delivery_price_per_km ?? 0),
                    'zone' => $branch->zone ? [
                        'id' => $branch->zone->id,
                        'name' => $branch->zone->name,
                    ] : null,
                ];
            }),
        ];
    }

    /**
     * Get all pieces for a specific service at a specific branch
     */
    public function getPiecesByServiceAndBranch(int $serviceId, int $branchId, ?int $categoryId = null): array
    {
        $locale = app()->getLocale();
        $service = $this->serviceRepository->find($serviceId);

        if (! $service) {
            return [
                'service' => null,
                'branch' => null,
                'pieces' => [],
            ];
        }

        $branch = \Modules\Branch\Models\Branch::query()
            ->where('id', $branchId)
            ->where('is_active', true)
            ->with(['vendor', 'zone'])
            ->first();

        if (! $branch) {
            return [
                'service' => [
                    'id' => $service->id,
                    'service_name' => $service->service_name,
                ],
                'branch' => null,
                'pieces' => [],
            ];
        }

        if (! ($service->is_active ?? true)) {
            return [
                'service' => [
                    'id' => $service->id,
                    'name' => $service->getTranslation('service_name', $locale),
                    'description' => $service->getTranslation('description', $locale, false) ?: null,
                ],
                'branch' => [
                    'id' => $branch->id,
                    'branch_id' => $branch->id,
                    'name' => $branch->getTranslation('name', $locale),
                    'delivery_price_per_km' => (float) ($branch->vendor?->delivery_price_per_km ?? 0),
                ],
                'pieces' => [],
                'message' => 'This service is not available',
            ];
        }

        // Check if the branch offers this service (catalog + branch pivot active)
        $branchOffersService = CatalogActiveFilter::activeServicesOnBranch($branch->services())
            ->where('services.id', $serviceId)
            ->exists();

        if (! $branchOffersService) {
            return [
                'service' => [
                    'id' => $service->id,
                    'name' => $service->getTranslation('service_name', $locale),
                ],
                'branch' => [
                    'id' => $branch->id,
                    'branch_id' => $branch->id,
                    'name' => $branch->getTranslation('name', $locale),
                    'delivery_price_per_km' => (float) ($branch->vendor?->delivery_price_per_km ?? 0),
                ],
                'pieces' => [],
                'message' => 'This branch does not offer this service',
            ];
        }

        // Only pieces active at catalog and branch, linked to this service at this branch
        $pieces = CatalogActiveFilter::scopeActivePieces(
            Piece::query()
                ->whereHas('services', function ($query) use ($serviceId, $branchId) {
                    $query->where('services.id', $serviceId)
                        ->where('services.is_active', true)
                        ->where('service_piece.branch_id', $branchId);
                })
                ->whereHas('branches', function ($query) use ($branchId) {
                    $query->where('branches.id', $branchId)
                        ->where('branch_piece.is_active', true);
                })
                ->when($categoryId, fn ($q) => $q->where('pieces.category_id', $categoryId))
        )
            ->with(['iconRelation'])
            ->get();

        $pieceIds = $pieces->pluck('id')->map(fn ($id) => (int) $id)->all();

        $pivotMap = \Modules\Piece\Support\PieceBranchOffering::pivotMapForPieces(
            $branchId,
            $pieceIds
        );

        // Pre-fetch service_piece prices for this service + branch across all pieces in 1 query
        $servicePiecePrices = $pieceIds === []
            ? collect()
            : DB::table('service_piece')
                ->where('service_id', $serviceId)
                ->where('branch_id', $branchId)
                ->whereIn('piece_id', $pieceIds)
                ->pluck('price', 'piece_id');

        // Pre-fetch all additional services for all pieces in 1 query
        $additionalServicesMap = \Modules\Piece\Support\PiecePricingFormatter::additionalServicesMapForPieces(
            $pieces,
            $branchId,
            $locale,
            true
        );

        return [
            'service' => [
                'id' => $service->id,
                'name' => $service->getTranslation('service_name', $locale),
                'description' => $service->getTranslation('description', $locale, false) ?: null,
            ],
            'branch' => [
                'id' => $branch->id,
                'branch_id' => $branch->id,
                'name' => $branch->getTranslation('name', $locale),
                'location' => $branch->location,
                'phone_number' => $branch->phone_number,
                'vendor' => [
                    'id' => $branch->vendor->id,
                    'name' => method_exists($branch->vendor, 'getTranslatedName')
                        ? $branch->vendor->getTranslatedName($locale)
                        : $branch->vendor->name,
                    'delivery_price_per_km' => (float) ($branch->vendor->delivery_price_per_km ?? 0),
                ],
                'delivery_price_per_km' => (float) ($branch->vendor?->delivery_price_per_km ?? 0),
            ],
            'pieces' => $pieces->map(function ($piece) use ($service, $branchId, $pivotMap, $locale, $servicePiecePrices, $additionalServicesMap) {
                $pivotRow = $pivotMap[(int) $piece->id] ?? null;
                $branchFields = \Modules\Piece\Support\PieceBranchOffering::branchApiFields(
                    $piece,
                    $branchId,
                    $locale,
                    $pivotRow,
                    true
                );

                $price = $servicePiecePrices->get((int) $piece->id);
                if ($price === null) {
                    $price = $service->getPriceForPieceAtBranch($piece->id, $branchId);
                }

                $row = array_merge($branchFields, [
                    'id' => $piece->id,
                    'icon' => $piece->iconRelation?->full_path,
                    'service_id' => $service->id,
                    'price' => (float) ($price ?? 0),
                ]);

                $additionalServices = collect($additionalServicesMap[(int) $piece->id] ?? [])
                    ->filter(fn (array $addition) => CatalogActivePresenter::isEffectivelyActive($addition))
                    ->values();

                $row['additional_services'] = $additionalServices;
                $row['additional_services_count'] = $additionalServices->count();

                return $row;
            })->values(),
        ];
    }
}
