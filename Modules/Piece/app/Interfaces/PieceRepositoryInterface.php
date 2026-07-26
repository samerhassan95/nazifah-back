<?php

namespace Modules\Piece\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Piece\Models\Piece;

interface PieceRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Piece;

    public function create(array $data): Piece;

    public function update(Piece $piece, array $data): bool;

    public function delete(Piece $piece): bool;
}
