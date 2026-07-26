<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Models\Admin;

interface AdminRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Admin;

    public function create(array $data): Admin;

    public function update(Admin $admin, array $data): bool;

    public function delete(Admin $admin): bool;
}
