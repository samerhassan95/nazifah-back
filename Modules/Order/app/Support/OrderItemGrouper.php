<?php

namespace Modules\Order\Support;

use App\Support\OrderItemDisplayNames;
use Illuminate\Support\Collection;

/**
 * Groups order_items that share line_group into one API cart line
 * with multiple main services.
 */
class OrderItemGrouper
{
    /**
     * @param  Collection<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    public static function toApiLines(Collection $items, int $branchId, string $lang, ?callable $mapAdditions = null): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = $item->line_group ? 'g:'.$item->line_group : 'i:'.$item->id;
            $groups[$key][] = $item;
        }

        $lines = [];
        foreach ($groups as $groupItems) {
            $groupItems = collect($groupItems)->values();
            $primary = $groupItems->first();
            $services = [];
            $servicesTotal = 0.0;
            $additionalServices = [];
            $acceptedAdditionsTotal = 0.0;

            foreach ($groupItems as $item) {
                if (! $item->service) {
                    continue;
                }
                $servicePrice = (float) $item->service_price;
                $servicesTotal += $servicePrice;
                $label = OrderItemDisplayNames::serviceName($item->service, $branchId, $lang);
                $services[] = [
                    'id' => $item->service->id,
                    'service_id' => $item->service->id,
                    'name' => $label,
                    'service_name' => $label,
                    'price' => $servicePrice,
                ];

                if ($mapAdditions) {
                    $mapped = $mapAdditions($item);
                    if (! empty($mapped['additional_services'])) {
                        $additionalServices = array_merge($additionalServices, $mapped['additional_services']);
                    }
                    $acceptedAdditionsTotal += (float) ($mapped['additional_services_total'] ?? 0);
                }
            }

            $quantity = (int) $primary->quantity;
            $itemStatus = $primary->vendor_status ?? 'accepted';
            if ($itemStatus === 'rejected') {
                $unitPrice = 0.0;
                $totalPrice = 0.0;
            } else {
                $totalPrice = round($servicesTotal * $quantity + $acceptedAdditionsTotal, 2);
                $unitPrice = $quantity > 0 ? round($totalPrice / $quantity, 2) : 0.0;
            }

            $lines[] = [
                'id' => $primary->id,
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
                'additional_services_total' => round($acceptedAdditionsTotal, 2),
                'additional_services' => $additionalServices,
                'status' => $itemStatus,
                'note' => $primary->notes,
                'image' => null,
            ];
        }

        return $lines;
    }
}
