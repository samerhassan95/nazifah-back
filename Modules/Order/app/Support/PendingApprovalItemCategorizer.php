<?php

namespace Modules\Order\Support;

use App\Support\OrderItemDisplayNames;
use Modules\Order\Models\Order;

/**
 * Shared accepted / rejected / modified grouping for pending-approval and tracking.
 */
class PendingApprovalItemCategorizer
{
    /**
     * @return array{accepted: list<array<string, mixed>>, rejected: list<array<string, mixed>>, modified: list<array<string, mixed>>}
     */
    public static function categorize(Order $order, string $lang, callable $imageUrlResolver): array
    {
        $acceptedItems = [];
        $rejectedItems = [];
        $modifiedItems = [];

        $branchId = (int) ($order->branch_id ?? 0);

        foreach (OrderItemGrouper::buckets($order->items) as $groupItems) {
            $primary = $groupItems->first();
            $pieceName = $primary->piece
                ? OrderItemDisplayNames::pieceName($primary->piece, $branchId, $lang)
                : 'Unknown';
            $quantity = (int) $primary->quantity;

            $byStatus = [
                'accepted' => collect(),
                'rejected' => collect(),
                'modified' => collect(),
            ];

            foreach ($groupItems as $item) {
                $status = $item->vendor_status ?? 'accepted';
                if (! isset($byStatus[$status])) {
                    $status = 'accepted';
                }
                $byStatus[$status]->push($item);
            }

            foreach (['accepted', 'rejected', 'modified'] as $status) {
                $statusItems = $byStatus[$status];
                if ($statusItems->isEmpty()) {
                    continue;
                }

                $statusPrimary = $statusItems->first();
                $statusPieceName = $statusPrimary->piece
                    ? OrderItemDisplayNames::pieceName($statusPrimary->piece, $branchId, $lang)
                    : $pieceName;

                $itemWithImage = $statusItems->first(fn ($item) => ! empty($item->images));
                $itemImage = $imageUrlResolver($itemWithImage?->images);

                $clientNote = $statusItems
                    ->map(fn ($item) => $item->notes)
                    ->filter(fn ($v) => filled($v))
                    ->first();

                $services = [];
                $servicesTotal = 0.0;
                $additionalServices = [];
                $ids = [];
                $vendorNotes = [];

                foreach ($statusItems as $item) {
                    $ids[] = (int) $item->id;
                    if ($item->vendor_notes) {
                        $vendorNotes[] = $item->vendor_notes;
                    }

                    if ($item->service) {
                        $servicePrice = (float) $item->service_price;
                        $servicesTotal += $servicePrice;
                        $label = OrderItemDisplayNames::serviceName($item->service, $branchId, $lang);
                        $services[] = [
                            'id' => $item->service->id,
                            'name' => $label,
                            'price' => $servicePrice,
                        ];
                    }

                    if ($item->relationLoaded('additionalServicesPivot')) {
                        foreach ($item->additionalServicesPivot as $pivot) {
                            $addition = $pivot->serviceAddition;
                            if (! $addition) {
                                continue;
                            }
                            $qty = (int) ($pivot->quantity ?? 1);
                            $price = OrderItemDisplayNames::storedAdditionalServiceUnitPrice($pivot);
                            $additionalServices[] = [
                                'id' => $addition->id,
                                'name' => OrderItemDisplayNames::additionalServiceName($addition, $branchId, $lang),
                                'price' => $price,
                                'quantity' => $qty,
                                'total' => $price * $qty,
                                'vendor_status' => $pivot->vendor_status ?? 'accepted',
                                'vendor_notes' => $pivot->vendor_notes,
                            ];
                        }
                    }
                }

                $additionalServices = self::uniqueAdditions($additionalServices);

                $serviceName = collect($services)->pluck('name')->filter()->implode('، ') ?: 'Unknown';
                $notes = $vendorNotes !== [] ? implode(' | ', array_unique($vendorNotes)) : null;
                $itemDescription = $clientNote ?: null;

                if ($status === 'rejected') {
                    $allAdditionsTotal = collect($additionalServices)->sum('total');
                    $servicesWithAdditions = $services;
                    foreach ($additionalServices as $addition) {
                        $servicesWithAdditions[] = [
                            'id' => $addition['id'] ?? null,
                            'name' => $addition['name'] ?? '',
                            'price' => (float) ($addition['price'] ?? 0),
                        ];
                    }
                    $servicesWithAdditions = self::uniqueServices($servicesWithAdditions);
                    $serviceNameWithAdditions = collect($servicesWithAdditions)
                        ->pluck('name')
                        ->filter()
                        ->implode('، ');

                    $rejectedItems[] = [
                        'id' => $ids[0],
                        'ids' => $ids,
                        'piece_name' => $statusPieceName,
                        'service_name' => $serviceNameWithAdditions !== '' ? $serviceNameWithAdditions : $serviceName,
                        'services' => $servicesWithAdditions,
                        'quantity' => $quantity,
                        'unit_price' => round($servicesTotal, 2),
                        'total_price' => round(($servicesTotal * $quantity) + $allAdditionsTotal, 2),
                        'vendor_notes' => $notes,
                        'note' => $clientNote,
                        'description' => $clientNote,
                        'image' => $itemImage,
                        'additional_services' => [],
                        'status' => 'rejected',
                    ];

                    continue;
                }

                if ($status === 'modified') {
                    $first = $statusItems->first();
                    $acceptedAdditionsTotal = collect($additionalServices)
                        ->filter(fn ($a) => ($a['vendor_status'] ?? 'accepted') !== 'rejected')
                        ->sum('total');
                    $modifiedTotal = round(($servicesTotal * $quantity) + $acceptedAdditionsTotal, 2);
                    $modifiedUnit = round($servicesTotal, 2);

                    $modifiedItems[] = [
                        'id' => $ids[0],
                        'ids' => $ids,
                        'piece_name' => $statusPieceName,
                        'service_name' => $serviceName,
                        'services' => $services,
                        'original_quantity' => $first->original_quantity ?? $quantity,
                        'original_unit_price' => (float) ($first->original_unit_price ?? $first->unit_price),
                        'original_total_price' => (float) ($statusItems->sum(fn ($i) => (float) ($i->original_total_price ?? $i->total_price))),
                        'modified_quantity' => $first->modified_quantity ?? $quantity,
                        'modified_unit_price' => $first->modified_unit_price !== null
                            ? (float) $first->modified_unit_price
                            : $modifiedUnit,
                        'modified_total_price' => $first->modified_total_price !== null
                            ? (float) $statusItems->sum(fn ($i) => (float) ($i->modified_total_price ?? 0))
                            : $modifiedTotal,
                        'quantity' => $quantity,
                        'unit_price' => $modifiedUnit,
                        'total_price' => $modifiedTotal,
                        'vendor_notes' => $notes,
                        'note' => $clientNote,
                        'description' => $itemDescription,
                        'image' => $itemImage,
                        'additional_services' => array_values(array_filter(
                            $additionalServices,
                            fn ($a) => ($a['vendor_status'] ?? 'accepted') !== 'rejected'
                        )),
                    ];

                    continue;
                }

                $acceptedAdditions = array_values(array_filter(
                    $additionalServices,
                    fn ($a) => ($a['vendor_status'] ?? 'accepted') === 'accepted'
                ));
                $rejectedAdditions = array_values(array_filter(
                    $additionalServices,
                    fn ($a) => ($a['vendor_status'] ?? 'accepted') === 'rejected'
                ));
                $acceptedAdditionsTotal = collect($acceptedAdditions)->sum('total');
                $unitPrice = round($servicesTotal, 2);
                $totalPrice = round(($unitPrice * $quantity) + $acceptedAdditionsTotal, 2);

                $rejectedServicesList = array_values(array_map(fn ($addition) => [
                    'id' => $addition['id'] ?? null,
                    'name' => $addition['name'] ?? '',
                    'price' => (float) ($addition['price'] ?? 0),
                    'quantity' => (int) ($addition['quantity'] ?? 1),
                ], $rejectedAdditions));

                $acceptedItems[] = [
                    'id' => $ids[0],
                    'ids' => $ids,
                    'piece_name' => $statusPieceName,
                    'service_name' => $serviceName,
                    'services' => $services,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'vendor_notes' => $notes,
                    'note' => $clientNote,
                    'description' => $itemDescription,
                    'image' => $itemImage,
                    'additional_services' => $acceptedAdditions,
                    'rejected_services' => $rejectedServicesList,
                ];
            }
        }

        return [
            'accepted' => $acceptedItems,
            'rejected' => self::mergeDuplicateRejectedItems($rejectedItems),
            'modified' => $modifiedItems,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $additionalServices
     * @return list<array<string, mixed>>
     */
    private static function uniqueAdditions(array $additionalServices): array
    {
        $seen = [];
        $unique = [];

        foreach ($additionalServices as $addition) {
            $key = (string) ($addition['id'] ?? '').'#'.(string) ($addition['name'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $addition;
        }

        return $unique;
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    private static function uniqueServices(array $services): array
    {
        $seen = [];
        $unique = [];

        foreach ($services as $service) {
            $name = trim((string) ($service['name'] ?? ''));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $unique[] = $service;
        }

        return $unique;
    }

    /**
     * @param  list<array<string, mixed>>  $rejectedItems
     * @return list<array<string, mixed>>
     */
    private static function mergeDuplicateRejectedItems(array $rejectedItems): array
    {
        $merged = [];

        foreach ($rejectedItems as $item) {
            $item['services'] = self::uniqueServices($item['services'] ?? []);
            $incomingNames = collect($item['services'])->pluck('name')->filter()->values();
            $item['service_name'] = $incomingNames->implode('، ') ?: ($item['service_name'] ?? 'Unknown');
            $pieceKey = (string) ($item['piece_name'] ?? '');

            if (! isset($merged[$pieceKey])) {
                $merged[$pieceKey] = $item;
                continue;
            }

            $existingNames = collect($merged[$pieceKey]['services'] ?? [])->pluck('name')->filter()->values();
            $isSameServices = $existingNames->sort()->values()->all() === $incomingNames->sort()->values()->all();

            $merged[$pieceKey]['ids'] = array_values(array_unique(array_merge(
                $merged[$pieceKey]['ids'] ?? [],
                $item['ids'] ?? []
            )));
            $merged[$pieceKey]['services'] = self::uniqueServices(array_merge(
                $merged[$pieceKey]['services'] ?? [],
                $item['services'] ?? []
            ));
            $merged[$pieceKey]['service_name'] = collect($merged[$pieceKey]['services'])
                ->pluck('name')
                ->filter()
                ->implode('، ') ?: 'Unknown';
            $merged[$pieceKey]['unit_price'] = round(
                collect($merged[$pieceKey]['services'])->sum(fn ($service) => (float) ($service['price'] ?? 0)),
                2
            );

            if ($isSameServices) {
                $merged[$pieceKey]['quantity'] = (int) ($merged[$pieceKey]['quantity'] ?? 0) + (int) ($item['quantity'] ?? 0);
            } else {
                $merged[$pieceKey]['quantity'] = max(
                    (int) ($merged[$pieceKey]['quantity'] ?? 1),
                    (int) ($item['quantity'] ?? 1)
                );
            }

            $merged[$pieceKey]['total_price'] = round(
                (float) ($merged[$pieceKey]['unit_price'] ?? 0) * (int) ($merged[$pieceKey]['quantity'] ?? 1),
                2
            );

            if (empty($merged[$pieceKey]['image']) && ! empty($item['image'])) {
                $merged[$pieceKey]['image'] = $item['image'];
            }
            if (empty($merged[$pieceKey]['vendor_notes']) && ! empty($item['vendor_notes'])) {
                $merged[$pieceKey]['vendor_notes'] = $item['vendor_notes'];
            }
        }

        return array_values($merged);
    }
}
