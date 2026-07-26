<?php

namespace Modules\Piece\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use Modules\Service\Support\ServiceAdditionBranchOffering;

/**
 * Consistent API shapes for pieces: no standalone piece price.
 * Prices live on service_piece (service_id + piece_id + branch_id).
 */
class PiecePricingFormatter
{
    /**
     * Piece with all services and service_piece prices at a branch.
     *
     * @return array{id: int, name: string, icon: ?string, services: array<int, array{service_id: int, service_name: string, price: float, icon: ?string}>}
     */
    public static function pieceWithServices(
        Piece $piece,
        ?int $branchId,
        string $lang,
        ?object $branchPivotRow = null,
        bool $localizedNameOnly = false
    ): array {
        $displayName = method_exists($piece, 'getTranslation')
            ? $piece->getTranslation('name', $lang)
            : $piece->name;

        $data = [
            'id' => $piece->id,
            'icon' => $piece->iconRelation?->full_path,
            'services' => [],
        ];

        if ($branchId) {
            $data = array_merge($data, PieceBranchOffering::branchApiFields(
                $piece,
                $branchId,
                $lang,
                $branchPivotRow,
                $localizedNameOnly
            ));
        } else {
            $data['name'] = $displayName;
            $data = array_merge($data, \App\Support\CatalogActivePresenter::piece($piece));
        }

        if (! $branchId) {
            $data['additional_services'] = self::additionalServicesForPiece($piece, null, $lang, $localizedNameOnly);

            return $data;
        }

        $services = $piece->relationLoaded('services')
            ? $piece->services
            : $piece->services()
                ->where('services.is_active', true)
                ->where('service_piece.branch_id', $branchId)
                ->get();

        $data['services'] = self::mapServicesWithPrices($services, $piece, $branchId, $lang, $localizedNameOnly);
        $data['additional_services'] = self::additionalServicesForPiece($piece, $branchId, $lang, $localizedNameOnly);

        return $data;
    }

    /**
     * Additional services explicitly assigned to this piece (service_addition_piece rows only).
     * Branch catalog offerings (branch_service_addition) are not included until linked to the piece.
     *
     * @return array<int, array{service_addition_id: int, service_name: string, name: array{ar: string, en: string}, icon: ?string, price: float, branch_id: ?int}>
     */
    public static function additionalServicesForPiece(
        Piece $piece,
        ?int $branchId,
        string $lang,
        bool $localizedNameOnly = false
    ): array {
        $piece->unsetRelation('additionalServices');

        $pivotQuery = DB::table('service_addition_piece')
            ->where('piece_id', (int) $piece->id);

        if ($branchId !== null) {
            $pivotQuery->where('branch_id', $branchId);
        }

        $pivots = $pivotQuery->get();

        if ($pivots->isEmpty()) {
            return [];
        }

        $additions = ServiceAddition::query()
            ->with('iconRelation')
            ->whereIn('id', $pivots->pluck('service_addition_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($pivots as $pivot) {
            $addition = $additions->get($pivot->service_addition_id);
            if (! $addition) {
                continue;
            }

            $pivotBranchId = $pivot->branch_id !== null ? (int) $pivot->branch_id : null;
            $effectiveBranchId = $branchId ?? $pivotBranchId;

            if ($effectiveBranchId) {
                $displayName = $addition->getDisplayNameAtBranch($effectiveBranchId, $lang);
                $names = ServiceAdditionBranchOffering::displayNameArrayForBranch($addition, $effectiveBranchId);
            } else {
                $displayName = $addition->getTranslation('name', $lang);
                $names = [
                    'ar' => $addition->getTranslation('name', 'ar', false) ?: '',
                    'en' => $addition->getTranslation('name', 'en', false) ?: '',
                ];
            }

            $effectivePrice = $effectiveBranchId
                ? $addition->getPriceForPieceAtBranch((int) $piece->id, $effectiveBranchId)
                : (float) ($pivot->price ?? $addition->price ?? 0);

            $row = array_merge(
                self::formatAdditionalServiceResponse(
                    $addition,
                    $displayName,
                    $names,
                    (float) $effectivePrice,
                    $pivotBranchId,
                    $effectiveBranchId,
                    $localizedNameOnly
                ),
                \App\Support\CatalogActivePresenter::serviceAddition($addition, $effectiveBranchId)
            );

            if (! \App\Support\CatalogActivePresenter::isEffectivelyActive($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array{ar: string, en: string}  $names
     */
    private static function formatAdditionalServiceResponse(
        ServiceAddition $addition,
        string $displayName,
        array $names,
        float $price,
        ?int $pivotBranchId,
        ?int $effectiveBranchId,
        bool $localizedNameOnly
    ): array {
        $base = [
            'id' => $addition->id,
            'service_addition_id' => $addition->id,
            'icon' => $addition->iconRelation?->full_path,
            'price' => $price,
            'branch_id' => $pivotBranchId,
        ];

        if ($localizedNameOnly) {
            $base['name'] = $displayName;

            return $base;
        }

        return array_merge($base, [
            'service_name' => $displayName,
            'display_name' => $displayName,
            'name' => $names,
            'catalog_name' => [
                'ar' => $addition->getTranslation('name', 'ar', false) ?: '',
                'en' => $addition->getTranslation('name', 'en', false) ?: '',
            ],
        ]);
    }

    /**
     * @param  Collection<int, Piece>  $pieces
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function additionalServicesMapForPieces(
        Collection $pieces,
        int $branchId,
        string $lang,
        bool $localizedNameOnly = false
    ): array {
        if ($pieces->isEmpty()) {
            return [];
        }

        $pieceIds = $pieces->pluck('id')->map(fn ($id) => (int) $id)->all();

        $pivots = DB::table('service_addition_piece')
            ->where('branch_id', $branchId)
            ->whereIn('piece_id', $pieceIds)
            ->get();

        if ($pivots->isEmpty()) {
            return array_fill_keys($pieceIds, []);
        }

        $additions = ServiceAddition::query()
            ->with('iconRelation')
            ->whereIn('id', $pivots->pluck('service_addition_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $map = array_fill_keys($pieceIds, []);

        foreach ($pivots as $pivot) {
            $pieceId = (int) $pivot->piece_id;
            $addition = $additions->get($pivot->service_addition_id);
            if (! $addition) {
                continue;
            }

            $pivotBranchId = $pivot->branch_id !== null ? (int) $pivot->branch_id : null;

            $displayName = $addition->getDisplayNameAtBranch($branchId, $lang);
            $names = ServiceAdditionBranchOffering::displayNameArrayForBranch($addition, $branchId);

            $row = array_merge(
                self::formatAdditionalServiceResponse(
                    $addition,
                    $displayName,
                    $names,
                    (float) $addition->getPriceForPieceAtBranch($pieceId, $branchId),
                    $pivotBranchId,
                    $branchId,
                    $localizedNameOnly
                ),
                \App\Support\CatalogActivePresenter::serviceAddition($addition, $branchId)
            );

            if (! \App\Support\CatalogActivePresenter::isEffectivelyActive($row)) {
                continue;
            }

            $map[$pieceId][] = $row;
        }

        return $map;
    }

    /**
     * Piece row when the parent context is a single service (e.g. list pieces for service 55).
     *
     * @return array{id: int, name: string, icon: ?string, service_id: int, price: float}
     */
    public static function pieceUnderService(
        Piece $piece,
        Service $service,
        int $branchId,
        string $lang,
        ?object $branchPivotRow = null,
        bool $localizedNameOnly = false
    ): array {
        return array_merge(
            PieceBranchOffering::branchApiFields($piece, $branchId, $lang, $branchPivotRow, $localizedNameOnly),
            [
                'id' => $piece->id,
                'icon' => $piece->iconRelation?->full_path,
                'service_id' => $service->id,
                'price' => (float) $service->getPriceForPieceAtBranch($piece->id, $branchId),
            ]
        );
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return array<int, array{service_id: int, service_name: string, price: float, icon: ?string}>
     */
    public static function mapServicesWithPrices(
        Collection $services,
        Piece $piece,
        int $branchId,
        string $lang,
        bool $localizedNameOnly = false
    ): array {
        // One service per piece: drop duplicate pivot rows / join duplicates (same service_id).
        $services = $services
            ->sortByDesc(function ($service) use ($branchId) {
                if (! $service->pivot || $service->pivot->branch_id === null) {
                    return 0;
                }

                return (int) $service->pivot->branch_id === $branchId ? 1 : 0;
            })
            ->unique('id')
            ->values();

        $serviceIds = $services->pluck('id')->map(fn ($id) => (int) $id)->all();
        $branchIconIds = $serviceIds === []
            ? collect()
            : DB::table('branch_service')
                ->where('branch_id', $branchId)
                ->whereIn('service_id', $serviceIds)
                ->whereNotNull('icon_id')
                ->pluck('icon_id', 'service_id');

        $branchServicePivots = $serviceIds === []
            ? collect()
            : DB::table('branch_service')
                ->where('branch_id', $branchId)
                ->whereIn('service_id', $serviceIds)
                ->get()
                ->keyBy('service_id');

        $iconsById = $branchIconIds->isEmpty()
            ? collect()
            : \Modules\Admin\Models\Icon::query()
                ->whereIn('id', $branchIconIds->values()->unique()->all())
                ->get()
                ->keyBy('id');

        return $services->map(function ($service) use ($piece, $branchId, $lang, $branchIconIds, $iconsById, $branchServicePivots, $localizedNameOnly) {
            $price = null;
            if ($service->pivot
                && (int) $service->pivot->branch_id === $branchId
                && $service->pivot->price !== null) {
                $price = (float) $service->pivot->price;
            } else {
                $price = $service->getPriceForPieceAtBranchOrNull($piece->id, $branchId);
            }

            $icon = self::resolveServiceIconAtBranch($service, (int) $service->id, $branchIconIds, $iconsById);
            $branchPivot = $branchServicePivots->get($service->id);

            $serviceLabel = method_exists($service, 'getTranslation')
                ? $service->getTranslation('service_name', $lang)
                : ($service->service_name ?? '');

            $row = array_merge([
                'service_id' => $service->id,
                'price' => (float) ($price ?? 0),
                'icon' => $icon,
            ], \App\Support\CatalogActivePresenter::service($service, $branchId, $branchPivot));

            if ($localizedNameOnly) {
                $row['name'] = $serviceLabel;
            } else {
                $row['service_name'] = $serviceLabel;
            }

            return \App\Support\CatalogActivePresenter::isEffectivelyActive($row) ? $row : null;
        })->filter()->values()->all();
    }

    /**
     * Branch_service icon overrides catalog service icon.
     */
    private static function resolveServiceIconAtBranch(
        Service $service,
        int $serviceId,
        \Illuminate\Support\Collection $branchIconIds,
        \Illuminate\Support\Collection $iconsById
    ): ?string {
        $branchIconId = $branchIconIds->get($serviceId);
        if ($branchIconId) {
            $branchIcon = $iconsById->get((int) $branchIconId);
            if ($branchIcon) {
                return $branchIcon->full_path;
            }
        }

        return $service->icon;
    }
}
