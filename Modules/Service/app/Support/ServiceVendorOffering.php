<?php

namespace Modules\Service\Support;

use Illuminate\Support\Facades\DB;
use Modules\Service\Models\Service;

class ServiceVendorOffering
{
    public static function upsert(int $vendorId, Service $service, array $data): void
    {
        $row = [
            'name' => isset($data['name']) ? json_encode($data['name']) : null,
            'description' => isset($data['description']) ? json_encode($data['description']) : null,
            'icon_id' => $data['icon_id'] ?? null,
            'updated_at' => now(),
        ];

        if (array_key_exists('is_active', $data)) {
            $row['is_active'] = (bool) $data['is_active'];
        }

        $existing = self::find($vendorId, $service->id);

        if ($existing) {
            $update = array_filter($row, fn ($value) => $value !== null);
            if (! empty($update)) {
                DB::table('vendor_service')
                    ->where('vendor_id', $vendorId)
                    ->where('service_id', $service->id)
                    ->update($update);
            }
        } else {
            DB::table('vendor_service')->insert(array_merge($row, [
                'vendor_id' => $vendorId,
                'service_id' => $service->id,
                'is_active' => $data['is_active'] ?? true,
                'created_at' => now(),
            ]));
        }
    }

    public static function toggleActive(int $vendorId, int $serviceId): ?bool
    {
        $existing = self::find($vendorId, $serviceId);
        if (! $existing) {
            return null;
        }

        $isActive = ! ((bool) ($existing->is_active ?? true));
        DB::table('vendor_service')
            ->where('vendor_id', $vendorId)
            ->where('service_id', $serviceId)
            ->update(['is_active' => $isActive, 'updated_at' => now()]);

        // Laundry-level off cascades to every branch offering for this vendor.
        // Re-enabling laundry does not auto-enable branches (explicit per-branch toggle).
        if (! $isActive) {
            self::deactivateOnVendorBranches($vendorId, $serviceId);
        }

        return $isActive;
    }

    /**
     * Set branch_service.is_active=false for this service on all vendor branches.
     */
    public static function deactivateOnVendorBranches(int $vendorId, int $serviceId): int
    {
        $branchIds = DB::table('branches')->where('vendor_id', $vendorId)->pluck('id');
        if ($branchIds->isEmpty()) {
            return 0;
        }

        return DB::table('branch_service')
            ->where('service_id', $serviceId)
            ->whereIn('branch_id', $branchIds)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public static function find(int $vendorId, int $serviceId): ?object
    {
        return DB::table('vendor_service')
            ->where('vendor_id', $vendorId)
            ->where('service_id', $serviceId)
            ->first();
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function displayNameArrayForVendor(Service $service, int $vendorId): array
    {
        $row = self::find($vendorId, $service->id);

        if ($row && $row->name) {
            $decoded = is_string($row->name) ? json_decode($row->name, true) : (array) $row->name;
            if (is_array($decoded)) {
                return [
                    'ar' => (string) ($decoded['ar'] ?? $decoded['en'] ?? ''),
                    'en' => (string) ($decoded['en'] ?? $decoded['ar'] ?? ''),
                ];
            }
        }

        return [
            'ar' => $service->getTranslation('service_name', 'ar', false) ?: '',
            'en' => $service->getTranslation('service_name', 'en', false) ?: '',
        ];
    }

    public static function displayNameForVendor(Service $service, int $vendorId, string $lang): string
    {
        $names = self::displayNameArrayForVendor($service, $vendorId);

        return $names[$lang] ?? $names['ar'] ?? $names['en'] ?? '';
    }

    public static function descriptionForVendor(Service $service, int $vendorId, string $lang): ?string
    {
        $row = self::find($vendorId, $service->id);

        if ($row && $row->description) {
            $decoded = is_string($row->description) ? json_decode($row->description, true) : (array) $row->description;
            if (is_array($decoded)) {
                return $decoded[$lang] ?? $decoded['ar'] ?? $decoded['en'] ?? null;
            }
        }

        if (! $service->description) {
            return null;
        }

        return $service->getTranslation('description', $lang, false) ?: null;
    }

    public static function iconIdForVendor(int $vendorId, Service $service): ?int
    {
        $row = self::find($vendorId, $service->id);

        if ($row && $row->icon_id) {
            return (int) $row->icon_id;
        }

        return $service->icon_id ? (int) $service->icon_id : null;
    }

    /**
     * Remove service from vendor catalog and detach from vendor branches/pieces.
     * Never deletes the system services row — other vendors and admin catalog stay intact.
     */
    public static function removeFromVendor(int $vendorId, int $serviceId): bool
    {
        $branchIds = DB::table('branches')->where('vendor_id', $vendorId)->pluck('id');
        $pieceIds = DB::table('pieces')->where('vendor_id', $vendorId)->pluck('id');

        if ($branchIds->isNotEmpty()) {
            DB::table('branch_service')
                ->where('service_id', $serviceId)
                ->whereIn('branch_id', $branchIds)
                ->delete();

            DB::table('service_piece')
                ->where('service_id', $serviceId)
                ->whereIn('branch_id', $branchIds)
                ->delete();
        }

        if ($pieceIds->isNotEmpty()) {
            DB::table('service_piece')
                ->where('service_id', $serviceId)
                ->whereIn('piece_id', $pieceIds)
                ->whereNull('branch_id')
                ->delete();
        }

        return DB::table('vendor_service')
            ->where('vendor_id', $vendorId)
            ->where('service_id', $serviceId)
            ->delete() > 0;
    }
}
