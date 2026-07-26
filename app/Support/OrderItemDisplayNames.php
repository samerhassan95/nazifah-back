<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Icon;
use Modules\Order\Models\OrderItemAdditionalService;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use Modules\Service\Support\ServiceAdditionBranchOffering;

/**
 * Branch-scoped display names for order API responses (user, vendor, driver).
 */
class OrderItemDisplayNames
{
    public static function pieceName(Piece $piece, ?int $branchId, string $lang): string
    {
        if ($branchId && $branchId > 0) {
            return $piece->getDisplayNameAtBranch($branchId, $lang);
        }

        return $piece->getTranslation('name', $lang, false) ?: (string) $piece->name;
    }

    public static function serviceName(Service $service, ?int $branchId, string $lang): string
    {
        if ($branchId && $branchId > 0) {
            $row = DB::table('branch_service')
                ->where('branch_id', $branchId)
                ->where('service_id', $service->id)
                ->first();

            if ($row && ! empty($row->name)) {
                $decoded = is_string($row->name) ? json_decode($row->name, true) : (array) $row->name;
                if (is_array($decoded)) {
                    $label = (string) ($decoded[$lang] ?? $decoded['ar'] ?? $decoded['en'] ?? '');
                    if ($label !== '') {
                        return $label;
                    }
                }
            }
        }

        return $service->getTranslation('service_name', $lang, false)
            ?: (is_string($service->service_name) ? $service->service_name : '');
    }

    public static function additionalServiceName(ServiceAddition $addition, ?int $branchId, string $lang): string
    {
        if ($branchId && $branchId > 0) {
            return $addition->getDisplayNameAtBranch($branchId, $lang);
        }

        return $addition->getTranslation('name', $lang, false) ?: (string) $addition->name;
    }

    public static function servicePiecePrice(Service $service, int $pieceId, ?int $branchId): float
    {
        if (! $branchId || $branchId <= 0) {
            return 0.0;
        }

        return (float) $service->getPriceForPieceAtBranch($pieceId, $branchId);
    }

    public static function additionalServicePrice(ServiceAddition $addition, int $pieceId, ?int $branchId): float
    {
        if (! $branchId || $branchId <= 0) {
            return (float) ($addition->price ?? 0);
        }

        return (float) $addition->getPriceForPieceAtBranch($pieceId, $branchId);
    }

    /**
     * Unit price saved on the order line (must match user tracking / calculate).
     */
    public static function storedAdditionalServiceUnitPrice(OrderItemAdditionalService $pivot): float
    {
        return (float) ($pivot->getAttributes()['price'] ?? $pivot->price ?? 0);
    }

    /**
     * Piece catalog icon URL (branch_piece has no icon override).
     */
    public static function pieceIconUrl(?Piece $piece): ?string
    {
        if (! $piece) {
            return null;
        }

        $path = $piece->iconRelation?->full_path ?: $piece->iconRelation?->path;

        return $path ? (string) $path : null;
    }

    /**
     * Branch_service.icon_id overrides catalog service icon when set.
     */
    public static function serviceIconUrl(?Service $service, ?int $branchId): ?string
    {
        if (! $service) {
            return null;
        }

        if ($branchId && $branchId > 0) {
            $branchIconId = DB::table('branch_service')
                ->where('branch_id', $branchId)
                ->where('service_id', $service->id)
                ->value('icon_id');

            if ($branchIconId) {
                $icon = Icon::query()->find((int) $branchIconId);
                $path = $icon?->full_path ?: $icon?->path;
                if ($path) {
                    return (string) $path;
                }
            }
        }

        $path = $service->iconRelation?->full_path
            ?: $service->iconRelation?->path
            ?: (is_string($service->icon) ? $service->icon : null);

        return $path ? (string) $path : null;
    }

    /**
     * Branch_service_addition.icon_id overrides catalog addition icon when set.
     */
    public static function additionalServiceIconUrl(?ServiceAddition $addition, ?int $branchId): ?string
    {
        if (! $addition) {
            return null;
        }

        $iconId = null;
        if ($branchId && $branchId > 0) {
            $iconId = ServiceAdditionBranchOffering::iconIdForBranch($branchId, $addition);
        } elseif ($addition->icon_id) {
            $iconId = (int) $addition->icon_id;
        }

        if ($iconId) {
            if ((int) ($addition->icon_id ?? 0) === (int) $iconId && $addition->iconRelation) {
                $path = $addition->iconRelation->full_path ?: $addition->iconRelation->path;

                return $path ? (string) $path : null;
            }

            $icon = Icon::query()->find((int) $iconId);
            $path = $icon?->full_path ?: $icon?->path;
            if ($path) {
                return (string) $path;
            }
        }

        $path = $addition->iconRelation?->full_path ?: $addition->iconRelation?->path;

        return $path ? (string) $path : null;
    }

    /**
     * @return array{id: int, name: string, price: float}
     */
    public static function additionalServiceLine(ServiceAddition $addition, int $branchId, string $lang, float $price): array
    {
        return [
            'id' => $addition->id,
            'name' => self::additionalServiceName($addition, $branchId, $lang),
            'price' => $price,
        ];
    }
}
