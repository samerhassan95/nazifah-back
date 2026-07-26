<?php

namespace Modules\Order\Support;

/**
 * Normalizes client order-item payloads before validation / pricing.
 *
 * Multiple main services on one piece stay on ONE cart line via service_ids[].
 * service_id is set to the first id for backward-compatible validation.
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

            $normalized[] = self::normalizeOne($item);
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function normalizeOne(array $item): array
    {
        $item = self::flattenNestedIds($item);

        $serviceIds = self::mainServiceIds($item);
        if ($serviceIds !== []) {
            $item['service_ids'] = $serviceIds;
            $item['service_id'] = $serviceIds[0];
        }

        return $item;
    }

    /**
     * Unique main service ids for a cart line (service_ids or singular service_id).
     *
     * @param  array<string, mixed>  $item
     * @return list<int>
     */
    public static function mainServiceIds(array $item): array
    {
        if (! empty($item['service_ids']) && is_array($item['service_ids'])) {
            return collect($item['service_ids'])
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        if (! empty($item['service_id'])) {
            return [(int) $item['service_id']];
        }

        return [];
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

        if (
            empty($item['service_ids'])
            && ! empty($item['services'])
            && is_array($item['services'])
        ) {
            $item['service_ids'] = collect($item['services'])
                ->map(function ($service) {
                    if (is_array($service)) {
                        return $service['id'] ?? $service['service_id'] ?? null;
                    }

                    return $service;
                })
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
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
}
