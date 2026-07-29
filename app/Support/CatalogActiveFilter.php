<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CatalogActiveFilter
{
    public static function activeServicesOnBranch(BelongsToMany $relation): BelongsToMany
    {
        return $relation
            ->where('services.is_active', true)
            ->where('branch_service.is_active', true)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('vendor_service')
                    ->join('branches', 'branches.vendor_id', '=', 'vendor_service.vendor_id')
                    ->whereColumn('branches.id', 'branch_service.branch_id')
                    ->whereColumn('vendor_service.service_id', 'services.id')
                    ->where('vendor_service.is_active', true);
            });
    }

    /**
     * Vendor catalog: admin-active services the vendor has adopted.
     */
    public static function activeVendorCatalogServices(BelongsToMany $relation): BelongsToMany
    {
        return $relation
            ->where('services.is_active', true)
            ->where('vendor_service.is_active', true);
    }

    public static function activePiecesOnBranch(BelongsToMany $relation): BelongsToMany
    {
        return $relation
            ->where('pieces.is_active', true)
            ->where('branch_piece.is_active', true);
    }

    public static function activeServiceAdditionsOnBranch(BelongsToMany $relation): BelongsToMany
    {
        return $relation
            ->where('service_additions.is_active', true)
            ->where('branch_service_addition.is_active', true);
    }

    /**
     * Filter service additions linked to a piece (service_addition_piece) for user catalog.
     */
    public static function scopeActiveServiceAdditionsForPiece(Builder|BelongsToMany $query, int $branchId): Builder|BelongsToMany
    {
        return $query
            ->where('service_additions.is_active', true)
            ->where(function (Builder $branchOffering) use ($branchId) {
                $branchOffering
                    ->whereNotExists(function ($sub) use ($branchId) {
                        $sub->selectRaw('1')
                            ->from('branch_service_addition')
                            ->whereColumn('branch_service_addition.service_addition_id', 'service_additions.id')
                            ->where('branch_service_addition.branch_id', $branchId);
                    })
                    ->orWhereExists(function ($sub) use ($branchId) {
                        $sub->selectRaw('1')
                            ->from('branch_service_addition')
                            ->whereColumn('branch_service_addition.service_addition_id', 'service_additions.id')
                            ->where('branch_service_addition.branch_id', $branchId)
                            ->where('branch_service_addition.is_active', true);
                    });
            });
    }

    public static function scopeActivePieces(Builder $query): Builder
    {
        return $query->where('pieces.is_active', true);
    }

    public static function scopeActiveServices(Builder|BelongsToMany $query): Builder|BelongsToMany
    {
        return $query->where('services.is_active', true);
    }

    public static function scopeActivePiecesOnBranchRelation(Builder $query, int $branchId): Builder
    {
        return $query->whereHas('branches', function (Builder $branchQuery) use ($branchId) {
            $branchQuery
                ->where('branches.id', $branchId)
                ->where('branch_piece.is_active', true);
        })->where('pieces.is_active', true);
    }
}
