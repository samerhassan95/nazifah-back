<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Modules\Admin\Interfaces\AdminRepositoryInterface;
use Modules\Admin\Models\Admin;

class AdminService
{
    public function __construct(
        private AdminRepositoryInterface $adminRepository
    ) {}

    public function getAllAdmins(array $filters = []): LengthAwarePaginator
    {
        return $this->adminRepository->all($filters);
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $filters = array_merge($filters, ['per_page' => $perPage]);

        return $this->adminRepository->all($filters);
    }

    public function getAdminById(int $id): ?Admin
    {
        return $this->adminRepository->find($id);
    }

    public function createAdmin(array $data): Admin
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return $this->adminRepository->create($data);
    }

    // Backwards-compatible alias used by controllers
    public function create(array $data): Admin
    {
        return $this->createAdmin($data);
    }

    public function updateAdmin(int $id, array $data): ?Admin
    {
        $admin = $this->adminRepository->find($id);

        if (! $admin) {
            return null;
        }

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $this->adminRepository->update($admin, $data);

        return $admin->fresh();
    }

    public function deleteAdmin(int $id): bool
    {
        $admin = $this->adminRepository->find($id);

        if (! $admin) {
            return false;
        }

        return $this->adminRepository->delete($admin);
    }
}
