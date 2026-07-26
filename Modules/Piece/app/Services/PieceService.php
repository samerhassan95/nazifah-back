<?php

namespace Modules\Piece\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Piece\Interfaces\PieceRepositoryInterface;
use Modules\Piece\Models\Piece;

class PieceService
{
    public function __construct(
        private PieceRepositoryInterface $pieceRepository
    ) {}

    public function getAllPieces(array $filters = []): LengthAwarePaginator
    {
        return $this->pieceRepository->all($filters);
    }

    public function getPieceById(int $id): ?Piece
    {
        return $this->pieceRepository->find($id);
    }

    public function createPiece(array $data): Piece
    {
        unset($data['base_price'], $data['price']);

        $piece = $this->pieceRepository->create($data);

        return $piece->fresh();
    }

    public function updatePiece(int $id, array $data): ?Piece
    {
        $piece = $this->pieceRepository->find($id);

        if (! $piece) {
            return null;
        }

        unset($data['base_price'], $data['price']);

        $this->pieceRepository->update($piece, $data);

        return $piece->fresh();
    }

    public function deletePiece(int $id): bool
    {
        $piece = $this->pieceRepository->find($id);

        if (! $piece) {
            return false;
        }

        return $this->pieceRepository->delete($piece);
    }

    /**
     * Get all services related to a piece (including service additions)
     * Optionally filter by branch_id to get branch-specific data
     */
    public function getServicesByPiece(int $pieceId, ?int $branchId = null): ?array
    {
        $piece = $this->pieceRepository->find($pieceId);

        if (! $piece) {
            return null;
        }

        // If branch_id is provided, verify the piece is available at that branch
        if ($branchId) {
            $branch = \Modules\Branch\Models\Branch::find($branchId);

            if (! $branch) {
                return null;
            }

            // Check if piece is available at this branch
            $pieceAtBranch = $branch->pieces()->where('pieces.id', $pieceId)->first();

            if (! $pieceAtBranch) {
                return null;
            }

            // Get services available at this branch for this piece
            $branchServiceIds = $branch->services()->pluck('services.id')->toArray();

            $services = $piece->services()
                ->with(['category', 'iconRelation'])
                ->whereIn('services.id', $branchServiceIds)
                ->get();

            // Get additional services for this piece at this specific branch ONLY
            // Do NOT include vendor-level (NULL branch_id) because additional services are branch-specific
            $additionalServices = $piece->additionalServices()
                ->with(['iconRelation'])
                ->where('service_addition_piece.branch_id', $branchId)
                ->get();

            // Map services using model helper for single effective price
            $servicesData = $services->map(function ($service) use ($piece, $branchId) {
                return [
                    'id' => $service->id,
                    'service_name' => $service->service_name,
                    'price' => $service->getPriceForPieceAtBranch($piece->id, $branchId),
                    'category' => $service->category ? [
                        'id' => $service->category->id,
                        'name' => $service->category->name,
                    ] : null,
                    'icon' => $service->icon,
                ];
            });

            // Map additional services using model helper for single effective price
            $additionalServicesData = $additionalServices->map(function ($addition) use ($piece, $branchId) {
                return [
                    'id' => $addition->id,
                    'name' => $addition->name,
                    'price' => $addition->getPriceForPieceAtBranch($piece->id, $branchId),
                    'icon' => $addition->iconRelation?->full_path,
                ];
            });

            return [
                'piece' => [
                    'id' => $piece->id,
                    'name' => $piece->name,
                    'icon' => $piece->iconRelation?->full_path ?? $piece->iconRelation?->path,
                ],
                'branch' => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                ],
                'services' => $servicesData,
                'additional_services' => $additionalServicesData,
            ];
        }

        // If no branch_id, return all services (original behavior)
        $services = $piece->services()->with(['category', 'iconRelation'])->get();
        $additionalServices = $piece->additionalServices()->with(['iconRelation'])->get();

        return [
            'piece' => [
                'id' => $piece->id,
                'name' => $piece->name,
                'icon' => $piece->iconRelation?->full_path ?? $piece->iconRelation?->path,
            ],
            'services' => $services->map(function ($service) {
                return [
                    'service_id' => $service->id,
                    'service_name' => $service->service_name,
                    'price' => (float) ($service->pivot->price ?? 0),
                    'category' => $service->category ? [
                        'id' => $service->category->id,
                        'name' => $service->category->name,
                    ] : null,
                    'icon' => $service->icon,
                    'is_active' => $service->is_active,
                ];
            }),
            'additional_services' => $additionalServices->map(function ($addition) {
                return [
                    'id' => $addition->id,
                    'name' => $addition->name,
                    'price' => $addition->price,
                    'icon' => $addition->iconRelation?->full_path,
                ];
            }),
        ];
    }
}
