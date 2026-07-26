<?php

namespace Modules\Vendor\Services;

use Illuminate\Support\Facades\DB;
use Modules\Vendor\Models\Vendor;
use Modules\Vendor\Models\VendorEmployee;
use Modules\Vendor\Models\VendorPermission;
use Modules\Vendor\Models\VendorRole;

class VendorSystemRolesSyncService
{
    /** @var array<string, int>|null */
    private ?array $permissionIdsByName = null;

    public function syncPermissions(): int
    {
        $count = 0;

        foreach (VendorSystemRolesCatalog::permissions() as $permission) {
            VendorPermission::updateOrCreate(
                ['name' => $permission['name']],
                [
                    'display_name_ar' => $permission['display_name_ar'],
                    'display_name_en' => $permission['display_name_en'],
                    'group' => $permission['group'],
                    'description_ar' => $permission['description_ar'] ?? null,
                    'description_en' => $permission['description_en'] ?? null,
                ]
            );
            $count++;
        }

        $this->permissionIdsByName = null;

        return $count;
    }

    public function replaceAllVendors(bool $purgeCustomRoles = false): array
    {
        $this->syncPermissions();

        $stats = [
            'vendors' => 0,
            'roles_created' => 0,
            'roles_removed' => 0,
            'employees_remapped' => 0,
            'assignments_remapped' => 0,
            'custom_roles_removed' => 0,
        ];

        Vendor::query()->orderBy('id')->chunkById(50, function ($vendors) use (&$stats, $purgeCustomRoles) {
            foreach ($vendors as $vendor) {
                $vendorStats = $this->replaceVendorRoles($vendor, $purgeCustomRoles);
                $stats['vendors']++;
                foreach ($vendorStats as $key => $value) {
                    $stats[$key] += $value;
                }
            }
        });

        return $stats;
    }

    public function seedForVendor(Vendor $vendor): void
    {
        if (VendorRole::where('vendor_id', $vendor->id)
            ->where('is_system', true)
            ->whereIn('name', VendorSystemRolesCatalog::systemRoleNames())
            ->exists()) {
            return;
        }

        $this->syncPermissions();
        $this->upsertSystemRolesForVendor($vendor);
    }

    /** @return array<string, int> */
    public function replaceVendorRoles(Vendor $vendor, bool $purgeCustomRoles = false): array
    {
        $stats = [
            'roles_created' => 0,
            'roles_removed' => 0,
            'employees_remapped' => 0,
            'assignments_remapped' => 0,
            'custom_roles_removed' => 0,
        ];

        DB::transaction(function () use ($vendor, $purgeCustomRoles, &$stats) {
            $canonicalNames = VendorSystemRolesCatalog::systemRoleNames();
            $legacyMap = VendorSystemRolesCatalog::legacySystemRoleMap();

            $existingRoles = VendorRole::withTrashed()
                ->where('vendor_id', $vendor->id)
                ->get();

            $newRoles = $this->upsertSystemRolesForVendor($vendor);
            $stats['roles_created'] = max(0, count($newRoles) - $existingRoles->whereIn('name', $canonicalNames)->count());

            $fallbackRoleId = $newRoles['customer_support']->id;

            foreach ($existingRoles as $oldRole) {
                if (in_array($oldRole->name, $canonicalNames, true)) {
                    continue;
                }

                if (! $purgeCustomRoles && ! $oldRole->is_system) {
                    continue;
                }

                $targetName = $legacyMap[$oldRole->name] ?? 'customer_support';
                $newRoleId = $newRoles[$targetName]->id ?? $fallbackRoleId;

                $stats['employees_remapped'] += VendorEmployee::where('vendor_id', $vendor->id)
                    ->where('vendor_role_id', $oldRole->id)
                    ->update([
                        'vendor_role_id' => $newRoleId,
                        'role' => $this->legacyEnumForRoleName($targetName),
                    ]);

                $stats['assignments_remapped'] += DB::table('vendor_employee_branch_assignments')
                    ->where('vendor_role_id', $oldRole->id)
                    ->update(['vendor_role_id' => $newRoleId]);
            }

            $removalQuery = VendorRole::withTrashed()->where('vendor_id', $vendor->id);

            if ($purgeCustomRoles) {
                $stats['custom_roles_removed'] = (clone $removalQuery)
                    ->where('is_system', false)
                    ->count();
            } else {
                $removalQuery->where('is_system', true);
            }

            $rolesToRemove = (clone $removalQuery)
                ->whereNotIn('name', $canonicalNames)
                ->get();

            $removedRoleIds = $rolesToRemove->pluck('id')->all();

            if ($removedRoleIds !== []) {
                DB::table('vendor_role_permission')->whereIn('vendor_role_id', $removedRoleIds)->delete();
                VendorRole::withTrashed()->whereIn('id', $removedRoleIds)->forceDelete();
                $stats['roles_removed'] = count($removedRoleIds);
            }
        });

        return $stats;
    }

    /** @return array<string, VendorRole> */
    private function upsertSystemRolesForVendor(Vendor $vendor): array
    {
        $permissionMap = $this->permissionIdsByName();
        $roles = [];

        foreach (VendorSystemRolesCatalog::systemRoles() as $name => $definition) {
            $permissionNames = $definition['permissions'] === 'all'
                ? array_keys($permissionMap)
                : $definition['permissions'];

            $permissionIds = collect($permissionNames)
                ->map(fn (string $permissionName) => $permissionMap[$permissionName] ?? null)
                ->filter()
                ->values()
                ->all();

            $role = VendorRole::withTrashed()->updateOrCreate(
                [
                    'vendor_id' => $vendor->id,
                    'name' => $name,
                ],
                [
                    'display_name_ar' => $definition['display_name_ar'],
                    'display_name_en' => $definition['display_name_en'],
                    'description_ar' => $definition['description_ar'],
                    'description_en' => $definition['description_en'],
                    'is_active' => true,
                    'is_system' => true,
                    'deleted_at' => null,
                ]
            );

            $role->permissions()->sync($permissionIds);
            $roles[$name] = $role;
        }

        return $roles;
    }

    /** @return array<string, int> */
    private function permissionIdsByName(): array
    {
        if ($this->permissionIdsByName === null) {
            $this->permissionIdsByName = VendorPermission::pluck('id', 'name')->all();
        }

        return $this->permissionIdsByName;
    }

    private function legacyEnumForRoleName(string $roleName): string
    {
        return VendorSystemRolesCatalog::usesManagerLegacyEnum($roleName) ? 'manager' : 'employee';
    }
}
