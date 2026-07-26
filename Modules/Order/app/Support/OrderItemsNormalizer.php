<?php

namespace Modules\Order\Support;

/**
 * Normalizes client order-item payloads before validation / pricing.
 *
 * Supports nested show-shape fields and expanding multiple main services
 * (service_ids) into one line per service — matching order_items.service_id.
 */
class OrderItemsNormalizer
{
    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item = self::flattenNestedIds($item);

            foreach (self::expandMainServices($item) as $expanded) {
                $normalized[] = $expanded;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function flattenNestedIds(array $item): array
    {
        if (empty($item['piece_id']) && isset($item['piece']['id'])) {
            $item['piece_id'] = $item['piece']['id'];
        }

        if (empty($item['service_id']) && isset($item['service']['id'])) {
            $item['service_id'] = $item['service']['id'];
        }

        if (! isset($item['additional_service_ids']) && ! empty($item['additional_services']) && is_array($item['additional_services'])) {
            $item['additional_service_ids'] = collect($item['additional_services'])
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return $item;
    }

    /**
     * Expand service_ids[] into one item per main service.
     * Keeps singular service_id payloads unchanged.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private static function expandMainServices(array $item): array
    {
        $serviceIds = [];

        if (! empty($item['service_ids']) && is_array($item['service_ids'])) {
            $serviceIds = collect($item['service_ids'])
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        if ($serviceIds === [] && ! empty($item['service_id'])) {
            return [$item];
        }

        if ($serviceIds === []) {
            // Leave invalid payloads for the validator (missing service_id).
            return [$item];
        }

        $expanded = [];
        foreach ($serviceIds as $serviceId) {
            $line = $item;
            $line['service_id'] = $serviceId;
            unset($line['service_ids']);
            $expanded[] = $line;
        }

        return $expanded;
    }
}
