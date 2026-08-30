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
     * Items the laundry did not reject (deliverable / billable lines).
     *
     * @param  Collection<int, mixed>  $items
     * @return Collection<int, mixed>
     */
    public static function withoutRejected(Collection $items): Collection
    {
        return $items
            ->filter(fn ($item) => ($item->vendor_status ?? 'accepted') !== 'rejected')
            ->values();
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @param  callable(mixed): (?string)|null  $imageResolver
     * @return list<array<string, mixed>>
     */
    public static function toApiLines(
        Collection $items,
        int $branchId,
        string $lang,
        ?callable $imageResolver = null,
        bool $splitByVendorStatus = true
    ): array {
        $lines = [];
        foreach (self::buckets($items) as $groupItems) {
            if ($splitByVendorStatus) {
                // If vendor accepted some services and rejected others on the same piece,
                // split so accepted services keep their price and rejected stay separate.
                $byStatus = $groupItems->groupBy(fn ($item) => $item->vendor_status ?? 'accepted');
                foreach ($byStatus as $statusItems) {
                    $lines[] = self::mapGroup(collect($statusItems)->values(), $branchId, $lang, $imageResolver);
                }

                continue;
            }

            $lines[] = self::mapGroup($groupItems, $branchId, $lang, $imageResolver);
        }

        return self::collapseRejectedLines($lines);
    }

    /**
     * Count physical piece lines (multi-service siblings count as one piece).
     *
     * @param  Collection<int, mixed>  $items
     */
    public static function totalPiecesCount(Collection $items): int
    {
        $total = 0;
        foreach (self::buckets($items) as $groupItems) {
            $total += (int) ($groupItems->first()->quantity ?? 1);
        }

        return $total;
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
            // Gate on the stored service_id/service_price columns, not the `service`
            // relation resolving — an orphaned/unresolved relation must not silently
            // drop the price the client owes from the total while its additions
            // (mapAdditions below, unconditional) still get counted.
            if ($item->service_id) {
                $servicePrice = (float) $item->service_price;
                $servicesTotal += $servicePrice;
                $label = $item->service
                    ? OrderItemDisplayNames::serviceName($item->service, $branchId, $lang)
                    : '';
                $services[] = [
                    'id' => $item->service_id,
                    'service_id' => $item->service_id,
                    'name' => $label,
                    'service_name' => $label,
                    'icon' => $item->service
                        ? OrderItemDisplayNames::serviceIconUrl($item->service, $branchId)
                        : null,
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

        if ($itemStatus === 'rejected') {
            foreach ($additionalServices as $addition) {
                $services[] = [
                    'id' => $addition['id'] ?? null,
                    'service_id' => $addition['id'] ?? null,
                    'name' => $addition['name'] ?? '',
                    'service_name' => $addition['name'] ?? '',
                    'icon' => $addition['icon'] ?? null,
                    'price' => (float) ($addition['price'] ?? 0),
                ];
            }
            $services = self::uniqueServicesByName($services);
            $additionalServices = [];
            $displayAdditionsTotal = 0.0;
            $unitPrice = round(collect($services)->sum(fn ($service) => (float) ($service['price'] ?? 0)), 2);
            $totalPrice = round($unitPrice * $quantity, 2);
        }

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

    /**
     * Qty-split / twin rejected rows of the same piece become one line,
     * with unique service names (main service vs addition with the same label).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private static function collapseRejectedLines(array $lines): array
    {
        $result = [];
        $rejectedIndexByPiece = [];

        foreach ($lines as $line) {
            if (($line['status'] ?? 'accepted') !== 'rejected') {
                $result[] = $line;

                continue;
            }

            foreach ($line['additional_services'] ?? [] as $addition) {
                $line['services'][] = [
                    'id' => $addition['id'] ?? null,
                    'service_id' => $addition['id'] ?? null,
                    'name' => $addition['name'] ?? '',
                    'service_name' => $addition['name'] ?? '',
                    'icon' => $addition['icon'] ?? null,
                    'price' => (float) ($addition['price'] ?? 0),
                ];
            }
            $line['services'] = self::uniqueServicesByName($line['services'] ?? []);
            $line['additional_services'] = [];
            $piece = is_array($line['piece'] ?? null) ? $line['piece'] : [];
            $pieceKey = (string) (($piece['id'] ?? '').'|'.($piece['name'] ?? ''));
            if ($pieceKey === '|') {
                $pieceKey = 'line:'.($line['id'] ?? spl_object_id((object) $line));
            }

            if (! isset($rejectedIndexByPiece[$pieceKey])) {
                $rejectedIndexByPiece[$pieceKey] = count($result);
                $line['service'] = $line['services'][0] ?? ($line['service'] ?? null);
                $line['additional_services'] = [];
                $line['additional_services_total'] = 0.0;
                $result[] = $line;

                continue;
            }

            $index = $rejectedIndexByPiece[$pieceKey];
            $existing = $result[$index];
            $existingNames = collect($existing['services'] ?? [])->pluck('name')->filter()->sort()->values()->all();
            $incomingNames = collect($line['services'] ?? [])->pluck('name')->filter()->sort()->values()->all();
            $isSameServices = $existingNames === $incomingNames;

            $existing['ids'] = array_values(array_unique(array_merge(
                $existing['ids'] ?? [],
                $line['ids'] ?? []
            )));
            $existing['services'] = self::uniqueServicesByName(array_merge(
                $existing['services'] ?? [],
                $line['services'] ?? []
            ));
            $existing['service'] = $existing['services'][0] ?? ($existing['service'] ?? null);
            $existing['additional_services'] = [];

            $servicesTotal = (float) collect($existing['services'])->sum(fn ($service) => (float) ($service['price'] ?? 0));

            if ($isSameServices) {
                $existing['quantity'] = (int) ($existing['quantity'] ?? 0) + (int) ($line['quantity'] ?? 0);
            } else {
                $existing['quantity'] = max((int) ($existing['quantity'] ?? 1), (int) ($line['quantity'] ?? 1));
            }

            $existing['unit_price'] = round($servicesTotal, 2);
            $existing['additional_services_total'] = 0.0;
            $existing['total_price'] = round($existing['unit_price'] * (int) $existing['quantity'], 2);

            if (empty($existing['image']) && ! empty($line['image'])) {
                $existing['image'] = $line['image'];
            }
            if (empty($existing['note']) && ! empty($line['note'])) {
                $existing['note'] = $line['note'];
            }

            $result[$index] = $existing;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    private static function uniqueServicesByName(array $services): array
    {
        $seen = [];
        $unique = [];

        foreach ($services as $service) {
            $name = trim((string) ($service['name'] ?? $service['service_name'] ?? ''));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $unique[] = $service;
        }

        return $unique;
    }
}
