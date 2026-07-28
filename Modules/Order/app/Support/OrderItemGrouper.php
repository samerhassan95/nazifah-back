<?php

namespace Modules\Order\Support;

use App\Support\OrderItemDisplayNames;
use Illuminate\Support\Collection;

/**
 * Groups order_items that share line_group (or legacy multi-service siblings)
 * into one API cart line with multiple main services.
 */
class OrderItemGrouper
{
    /**
     * @param  Collection<int, mixed>  $items
     * @param  callable(mixed): (?string)|null  $imageResolver
     * @return list<array<string, mixed>>
     */
    public static function toApiLines(
        Collection $items,
        int $branchId,
        string $lang,
        ?callable $imageResolver = null
    ): array {
        $lines = [];
        foreach (self::buckets($items) as $groupItems) {
            // If vendor accepted some services and rejected others on the same piece,
            // split so accepted services keep their price and rejected stay separate.
            $byStatus = $groupItems->groupBy(fn ($item) => $item->vendor_status ?? 'accepted');
            foreach ($byStatus as $statusItems) {
                $lines[] = self::mapGroup(collect($statusItems)->values(), $branchId, $lang, $imageResolver);
            }
        }

        return $lines;
    }

    /**
     * Bucket order items by line_group / legacy multi-service siblings.
     * Same-service qty-split rows stay as individual buckets.
     *
     * @param  Collection<int, mixed>  $items
     * @return list<Collection<int, mixed>>
     */
    public static function buckets(Collection $items): array
    {
        $buckets = [];

        foreach ($items as $item) {
            $key = self::groupKey($item, $items);
            $buckets[$key][] = $item;
        }

        $result = [];
        foreach ($buckets as $groupItems) {
            $groupItems = collect($groupItems)->values();

            // Legacy qty-split rows (same service repeated): keep separate lines.
            $serviceIds = $groupItems->pluck('service_id')->map(fn ($id) => (int) $id)->all();
            $uniqueServiceIds = array_values(array_unique($serviceIds));
            if (
                ! $groupItems->first()->line_group
                && count($groupItems) > 1
                && count($uniqueServiceIds) !== count($groupItems)
            ) {
                foreach ($groupItems as $solo) {
                    $result[] = collect([$solo]);
                }

                continue;
            }

            $result[] = $groupItems;
        }

        return $result;
    }

    /**
     * @param  Collection<int, mixed>  $allItems
     */
    private static function groupKey(mixed $item, Collection $allItems): string
    {
        if (! empty($item->line_group)) {
            return 'g:'.$item->line_group;
        }

        // Legacy orders created before line_group: merge same piece + note/image
        // only when sibling rows use different main services.
        $pieceId = (int) $item->piece_id;
        $siblings = $allItems->filter(function ($other) use ($item, $pieceId) {
            return empty($other->line_group)
                && (int) $other->piece_id === $pieceId
                && (string) ($other->notes ?? '') === (string) ($item->notes ?? '')
                && (string) ($other->images ?? '') === (string) ($item->images ?? '')
                && (int) $other->quantity === (int) $item->quantity;
        });

        $uniqueServices = $siblings->pluck('service_id')->map(fn ($id) => (int) $id)->unique()->count();
        if ($siblings->count() > 1 && $uniqueServices === $siblings->count()) {
            return 'legacy:'.$pieceId.':'.(string) ($item->notes ?? '').':'.(string) ($item->images ?? '').':'.$item->quantity;
        }

        return 'i:'.$item->id;
    }

    /**
     * @param  Collection<int, mixed>  $groupItems
     * @return array<string, mixed>
     */
    private static function mapGroup(
        Collection $groupItems,
        int $branchId,
        string $lang,
        ?callable $imageResolver
    ): array {
        $primary = $groupItems->first();
        $services = [];
        $servicesTotal = 0.0;
        $additionalServices = [];
        $acceptedAdditionsTotal = 0.0;
        $allAdditionsTotal = 0.0;
        $ids = [];

        foreach ($groupItems as $item) {
            $ids[] = (int) $item->id;
            if ($item->service) {
                $servicePrice = (float) $item->service_price;
                $servicesTotal += $servicePrice;
                $label = OrderItemDisplayNames::serviceName($item->service, $branchId, $lang);
                $services[] = [
                    'id' => $item->service->id,
                    'service_id' => $item->service->id,
                    'name' => $label,
                    'service_name' => $label,
                    'icon' => OrderItemDisplayNames::serviceIconUrl($item->service, $branchId),
                    'price' => $servicePrice,
                ];
            }

            $mappedAdditions = self::mapAdditions($item, $branchId, $lang);
            if ($mappedAdditions['additional_services'] !== []) {
                $additionalServices = array_merge($additionalServices, $mappedAdditions['additional_services']);
            }
            $acceptedAdditionsTotal += $mappedAdditions['additional_services_total'];
            $allAdditionsTotal += $mappedAdditions['all_additional_services_total'];
        }

        $quantity = (int) $primary->quantity;
        $itemStatus = $primary->vendor_status ?? 'accepted';
        $unitPrice = round($servicesTotal, 2);
        $displayAdditionsTotal = $itemStatus === 'rejected'
            ? $allAdditionsTotal
            : $acceptedAdditionsTotal;
        $totalPrice = round(($unitPrice * $quantity) + $displayAdditionsTotal, 2);

        return [
            'id' => $primary->id,
            'ids' => $ids,
            'line_group' => $primary->line_group,
            'piece' => $primary->piece ? [
                'id' => $primary->piece->id,
                'name' => OrderItemDisplayNames::pieceName($primary->piece, $branchId, $lang),
                'icon' => OrderItemDisplayNames::pieceIconUrl($primary->piece),
            ] : null,
            'service' => $services[0] ?? null,
            'services' => $services,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'additional_services_total' => round($displayAdditionsTotal, 2),
            'additional_services' => $additionalServices,
            'status' => $itemStatus,
            'note' => $primary->notes,
            'image' => $imageResolver ? $imageResolver($primary) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapSingle(
        mixed $item,
        int $branchId,
        string $lang,
        ?callable $imageResolver
    ): array {
        return self::mapGroup(collect([$item]), $branchId, $lang, $imageResolver);
    }

    /**
     * @return array{additional_services: list<array<string, mixed>>, additional_services_total: float, all_additional_services_total: float}
     */
    private static function mapAdditions(mixed $item, int $branchId, string $lang): array
    {
        $additionalServices = [];
        $acceptedAdditionsTotal = 0.0;
        $allAdditionsTotal = 0.0;

        if (! $item->relationLoaded('additionalServicesPivot')) {
            return [
                'additional_services' => $additionalServices,
                'additional_services_total' => $acceptedAdditionsTotal,
                'all_additional_services_total' => $allAdditionsTotal,
            ];
        }

        foreach ($item->additionalServicesPivot as $pivot) {
            if (! $pivot->serviceAddition) {
                continue;
            }
            $additionPrice = OrderItemDisplayNames::storedAdditionalServiceUnitPrice($pivot);
            $qty = (int) ($pivot->quantity ?? 1);
            $total = $additionPrice * $qty;
            $row = [
                'id' => $pivot->serviceAddition->id,
                'name' => OrderItemDisplayNames::additionalServiceName($pivot->serviceAddition, $branchId, $lang),
                'price' => $additionPrice,
                'quantity' => $qty,
                'total' => $total,
                'total_price' => $total,
                'icon' => OrderItemDisplayNames::additionalServiceIconUrl($pivot->serviceAddition, $branchId),
                'status' => $pivot->vendor_status ?? 'accepted',
                'vendor_status' => $pivot->vendor_status ?? 'accepted',
                'vendor_notes' => $pivot->vendor_notes,
                'notes' => $pivot->vendor_notes,
            ];
            $additionalServices[] = $row;
            $allAdditionsTotal += $total;
            if (($row['status'] ?? 'accepted') !== 'rejected') {
                $acceptedAdditionsTotal += $total;
            }
        }

        return [
            'additional_services' => $additionalServices,
            'additional_services_total' => $acceptedAdditionsTotal,
            'all_additional_services_total' => $allAdditionsTotal,
        ];
    }
}
