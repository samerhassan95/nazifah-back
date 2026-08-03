<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;

/**
 * Consistent is_active fields for catalog API responses.
 *
 * - Vendor catalog only: is_active = vendor_service.is_active
 * - Branch context: vendor_is_active + branch_is_active, and is_active = both (effective)
 * - System/client without vendor: is_active starts as services.is_active; with branch also ANDs branch (+ vendor when resolvable)
 */
class CatalogActivePresenter
{
    public static function service(
        ?Service $service,
        ?int $branchId = null,
        ?object $branchPivot = null,
        ?int $vendorId = null,
        ?object $vendorPivot = null
    ): array {
        if (! $service) {
            return ['is_active' => false];
        }

        $systemActive = (bool) ($service->is_active ?? true);

        if ($vendorId === null && $branchId !== null) {
            $vendorId = self::vendorIdForBranch($branchId);
        }

        $vendorActive = null;
        if ($vendorId !== null) {
            $pivot = $vendorPivot ?? DB::table('vendor_service')
                ->where('vendor_id', $vendorId)
                ->where('service_id', $service->id)
                ->first();

            $vendorActive = $pivot ? (bool) ($pivot->is_active ?? true) : true;
        }

        $branchActive = null;
        if ($branchId !== null) {
            $pivot = $branchPivot ?? DB::table('branch_service')
                ->where('branch_id', $branchId)
                ->where('service_id', $service->id)
                ->first();

            $branchActive = $pivot
                ? (bool) ($pivot->is_active ?? true)
                : true;
        }

        $flags = [];

        if ($vendorActive !== null) {
            $flags['vendor_is_active'] = $vendorActive;
        }

        if ($branchActive !== null) {
            $flags['branch_is_active'] = $branchActive;
        }

        // Single flag for UI switches / "is this usable?":
        // laundry-only → vendor; branch → vendor ∧ branch; else system.
        if ($branchActive !== null && $vendorActive !== null) {
            $flags['is_active'] = $systemActive && $vendorActive && $branchActive;
        } elseif ($vendorActive !== null) {
            $flags['is_active'] = $systemActive && $vendorActive;
        } elseif ($branchActive !== null) {
            $flags['is_active'] = $systemActive && $branchActive;
        } else {
            $flags['is_active'] = $systemActive;
        }

        return $flags;
    }

    public static function piece(?Piece $piece, ?int $branchId = null, ?object $branchPivot = null): array
    {
        if (! $piece) {
            return ['is_active' => false];
        }

        $flags = ['is_active' => (bool) ($piece->is_active ?? true)];

        if ($branchId !== null) {
            $pivot = $branchPivot ?? DB::table('branch_piece')
                ->where('branch_id', $branchId)
                ->where('piece_id', $piece->id)
                ->first();

            $flags['branch_is_active'] = $pivot
                ? (bool) ($pivot->is_active ?? true)
                : true;

            $flags['is_active'] = $flags['is_active'] && $flags['branch_is_active'];
        }

        return $flags;
    }

    public static function serviceAddition(?ServiceAddition $addition, ?int $branchId = null, ?object $branchPivot = null): array
    {
        if (! $addition) {
            return ['is_active' => false];
        }

        $flags = ['is_active' => (bool) ($addition->is_active ?? true)];

        if ($branchId !== null) {
            $pivot = $branchPivot ?? DB::table('branch_service_addition')
                ->where('branch_id', $branchId)
                ->where('service_addition_id', $addition->id)
                ->first();

            // No branch offering row yet — treat branch as active if linked on a piece at branch.
            if (! $pivot) {
                $linkedOnPiece = DB::table('service_addition_piece')
                    ->where('branch_id', $branchId)
                    ->where('service_addition_id', $addition->id)
                    ->exists();
                $flags['branch_is_active'] = $linkedOnPiece;
            } else {
                $flags['branch_is_active'] = (bool) ($pivot->is_active ?? true);
            }

            $flags['is_active'] = $flags['is_active'] && $flags['branch_is_active'];
        }

        return $flags;
    }

    public static function fromServicePivot(?object $pivot): array
    {
        return [
            'is_active' => $pivot ? (bool) ($pivot->is_active ?? true) : true,
        ];
    }

    /**
     * Effective visibility for new orders / user catalog (catalog AND branch must be active).
     */
    public static function isEffectivelyActive(array $flags): bool
    {
        return (bool) ($flags['is_active'] ?? true);
    }

    private static function vendorIdForBranch(int $branchId): ?int
    {
        $vendorId = DB::table('branches')->where('id', $branchId)->value('vendor_id');

        return $vendorId !== null ? (int) $vendorId : null;
    }
}
