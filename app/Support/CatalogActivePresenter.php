<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;

/**
 * Consistent is_active fields for catalog API responses.
 * is_active = vendor/catalog level (priority). branch_is_active = branch pivot when branch context exists.
 */
class CatalogActivePresenter
{
    public static function service(?Service $service, ?int $branchId = null, ?object $branchPivot = null): array
    {
        if (! $service) {
            return ['is_active' => false];
        }

        $flags = ['is_active' => (bool) ($service->is_active ?? true)];

        if ($branchId !== null) {
            $pivot = $branchPivot ?? DB::table('branch_service')
                ->where('branch_id', $branchId)
                ->where('service_id', $service->id)
                ->first();

            $flags['branch_is_active'] = $pivot
                ? (bool) ($pivot->is_active ?? true)
                : true;
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
        $catalog = (bool) ($flags['is_active'] ?? true);

        return $catalog && (bool) ($flags['branch_is_active'] ?? true);
    }
}
