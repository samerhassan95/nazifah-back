<?php

namespace Modules\Service\Services;

use App\Support\CatalogActiveFilter;
use App\Support\CatalogActivePresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
                    'image_logo' => $branch->vendor ? $uploadService->getFullUrl($branch->vendor->logo) : null,
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
    public function getPiecesByServiceAndBranch(int $serviceId, int $branchId): array
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
        )
            ->with(['iconRelation'])
            ->get();

        $pivotMap = \Modules\Piece\Support\PieceBranchOffering::pivotMapForPieces(
            $branchId,
            $pieces->pluck('id')->map(fn ($id) => (int) $id)->all()
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
            'pieces' => $pieces->map(function ($piece) use ($service, $branchId, $pivotMap, $locale) {
                $row = PiecePricingFormatter::pieceUnderService(
                    $piece,
                    $service,
                    $branchId,
                    $locale,
                    $pivotMap[(int) $piece->id] ?? null,
                    true

                );
                $additionalServices = collect(PiecePricingFormatter::additionalServicesForPiece($piece, $branchId, $locale, true))
                    ->filter(fn (array $addition) => CatalogActivePresenter::isEffectivelyActive($addition))
                    ->values();
                $row['additional_services'] = $additionalServices;
                $row['additional_services_count'] = $additionalServices->count();

                return $row;
            })->values(),
        ];
    }
}
