<?php

namespace Modules\Admin\Services;

use App\Services\UploadFilesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\IconType;
use Modules\Admin\Interfaces\IconRepositoryInterface;

class IconService
{
    protected IconRepositoryInterface $iconRepository;

    protected UploadFilesService $uploadFilesService;

    public function __construct(
        IconRepositoryInterface $iconRepository,
        UploadFilesService $uploadFilesService
    ) {
        $this->iconRepository = $iconRepository;
        $this->uploadFilesService = $uploadFilesService;
    }

    public function getAllIcons($type = null)
    {
        if ($type) {
            return $this->iconRepository->findByType($type);
        }

        return $this->iconRepository->all();
    }

    /**
     * Icons of the given type (or all types) not yet assigned by this vendor.
     */
    public function getUnusedIconsForVendor(int $vendorId, ?string $type = null): Collection
    {
        if ($type) {
            $usedIds = $this->getUsedIconIdsForVendor($vendorId, $type);

            return $this->iconRepository->findByTypeExcludingIds($type, $usedIds);
        }

        $usedIds = array_values(array_unique(array_merge(
            $this->getUsedIconIdsForVendor($vendorId, IconType::PIECE->value),
            $this->getUsedIconIdsForVendor($vendorId, IconType::SERVICE->value),
            $this->getUsedIconIdsForVendor($vendorId, IconType::ADDITIONS->value),
        )));

        return $this->iconRepository->findExcludingIds($usedIds);
    }

    /**
     * Collect icon IDs already used by a vendor for a specific icon type.
     *
     * @return list<int>
     */
    public function getUsedIconIdsForVendor(int $vendorId, string $type): array
    {
        return match ($type) {
            IconType::PIECE->value => DB::table('pieces')
                ->where('vendor_id', $vendorId)
                ->whereNotNull('icon_id')
                ->pluck('icon_id')
                ->map(fn ($id) => (int) $id)
                ->all(),

            IconType::SERVICE->value => array_values(array_unique(array_merge(
                DB::table('vendor_service')
                    ->where('vendor_id', $vendorId)
                    ->whereNotNull('icon_id')
                    ->pluck('icon_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
                DB::table('branch_service')
                    ->join('branches', 'branches.id', '=', 'branch_service.branch_id')
                    ->where('branches.vendor_id', $vendorId)
                    ->whereNotNull('branch_service.icon_id')
                    ->pluck('branch_service.icon_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            ))),

            IconType::ADDITIONS->value => array_values(array_unique(array_merge(
                DB::table('service_additions')
                    ->where('vendor_id', $vendorId)
                    ->whereNotNull('icon_id')
                    ->pluck('icon_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
                DB::table('branch_service_addition')
                    ->join('branches', 'branches.id', '=', 'branch_service_addition.branch_id')
                    ->where('branches.vendor_id', $vendorId)
                    ->whereNotNull('branch_service_addition.icon_id')
                    ->pluck('branch_service_addition.icon_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            ))),

            IconType::CATEGORY->value => DB::table('categories')
                ->whereNotNull('icon_id')
                ->pluck('icon_id')
                ->map(fn ($id) => (int) $id)
                ->all(),

            default => [],
        };
    }

    public function getIconsPaginated(int $perPage = 15, $type = null)
    {
        if ($type) {
            return $this->iconRepository->paginateByType($perPage, $type);
        }

        return $this->iconRepository->paginate($perPage);
    }

    public function getIconById(int $id)
    {
        return $this->iconRepository->find($id);
    }

    public function createIcon(array $data)
    {
        if (isset($data['icon_file'])) {
            $data['path'] = $this->uploadFilesService->uploadImage(
                $data['icon_file'],
                'icons'
            );
            unset($data['icon_file']);
        }

        return $this->iconRepository->create($data);
    }

    public function updateIcon(int $id, array $data)
    {
        $icon = $this->iconRepository->find($id);

        if (isset($data['icon_file'])) {
            $data['path'] = $this->uploadFilesService->uploadImage(
                $data['icon_file'],
                'icons',
                $icon->path
            );
            unset($data['icon_file']);
        }

        return $this->iconRepository->update($id, $data);
    }

    public function deleteIcon(int $id)
    {
        $icon = $this->iconRepository->find($id);

        // Delete the file if it exists
        if ($icon->path) {
            $this->uploadFilesService->deleteFile($icon->path);
        }

        return $this->iconRepository->delete($id);
    }
}
