<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\PieceRepositoryInterface;
use Modules\Piece\Models\Piece;

class PieceService
{
    public function __construct(
        private PieceRepositoryInterface $pieceRepository
    ) {}

    public function getAllPieces(array $filters = []): LengthAwarePaginator
    {
        return $this->Repository->all($filters);
    }

    public function getPieceById(int $id): ?Piece
    {
        return $this->Repository->find($id);
    }

    public function createPiece(array $data): Piece
    {
        return $this->Repository->create($data);
    }

    public function updatePiece(int $id, array $data): ?Piece
    {
        $piece = $this->Repository->find($id);

        if (! $piece) {
            return null;
        }

        $this->Repository->update($piece, $data);

        return $piece->fresh();
    }

    public function deletePiece(int $id): bool
    {
        $piece = $this->Repository->find($id);

        if (! $piece) {
            return false;
        }

        return $this->Repository->delete($piece);
    }

    public function togglePieceStatus(int $id): ?Piece
    {
        $piece = $this->Repository->find($id);

        if (! $piece) {
            return null;
        }

        $this->Repository->toggleStatus($piece);

        return $piece->fresh();
    }

    public function getStatistics(): array
    {
        return $this->Repository->getStatistics();
    }
}
