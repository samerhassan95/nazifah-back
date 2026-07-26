<?php

namespace Modules\Order\Services;

use Modules\Branch\Models\Branch;
use Modules\Service\Models\Service;

class PricingService
{
    /**
     * Calculate the total price for a service + piece combination at a branch
     *
     * @throws \Exception
     */
    public function calculateServicePiecePrice(int $branchId, int $serviceId, int $pieceId): float
    {
        $branch = Branch::findOrFail($branchId);

        // Get service price (branch-specific or base price)
        $servicePrice = $branch->getServicePrice($serviceId);
        if ($servicePrice === null) {
            throw new \Exception("Service price not found for service ID {$serviceId} at branch ID {$branchId}");
        }

        // Get piece price from service_piece pivot
        $piecePrice = $branch->getPiecePriceForService($serviceId, $pieceId);
        if ($piecePrice === null) {
            throw new \Exception("Piece price not found for piece ID {$pieceId} in service ID {$serviceId}");
        }

        return $servicePrice + $piecePrice;
    }

    /**
     * Calculate the price for an additional service for a piece at a branch
     *
     * @throws \Exception
     */
    public function calculateAdditionalServicePrice(int $branchId, int $pieceId, int $serviceAdditionId): float
    {
        $branch = Branch::findOrFail($branchId);

        $price = $branch->getAdditionalServicePrice($serviceAdditionId, $pieceId);

        if ($price === null) {
            throw new \Exception(
                "Additional service price not found for service addition ID {$serviceAdditionId}, ".
                "piece ID {$pieceId} at branch ID {$branchId}"
            );
        }

        return $price;
    }

    /**
     * Calculate the total for an order item
     *
     * Expected format:
     * [
     *     'branch_id' => int,
     *     'service_id' => int,
     *     'piece_id' => int,
     *     'quantity' => int,
     *     'additional_services' => [
     *         ['service_addition_id' => int, 'quantity' => int],
     *         ...
     *     ]
     * ]
     *
     * @throws \Exception
     */
    public function calculateOrderItemTotal(array $orderItemData): float
    {
        $branchId = $orderItemData['branch_id'];
        $serviceId = $orderItemData['service_id'];
        $pieceId = $orderItemData['piece_id'];
        $quantity = $orderItemData['quantity'];

        // Calculate base price (service + piece)
        $basePrice = $this->calculateServicePiecePrice($branchId, $serviceId, $pieceId);

        // Calculate additional services total
        $additionalServicesTotal = 0;
        if (isset($orderItemData['additional_services']) && is_array($orderItemData['additional_services'])) {
            foreach ($orderItemData['additional_services'] as $addition) {
                $additionPrice = $this->calculateAdditionalServicePrice(
                    $branchId,
                    $pieceId,
                    $addition['service_addition_id']
                );
                $additionQuantity = $addition['quantity'] ?? 1;
                $additionalServicesTotal += ($additionPrice * $additionQuantity);
            }
        }

        // Total = (base price + additional services) * quantity
        return ($basePrice + $additionalServicesTotal) * $quantity;
    }

    /**
     * Calculate the total for all order items
     *
     * @param  array  $orderItems  Array of order item data
     *
     * @throws \Exception
     */
    public function calculateOrderTotal(array $orderItems): float
    {
        $total = 0;

        foreach ($orderItems as $item) {
            $total += $this->calculateOrderItemTotal($item);
        }

        return $total;
    }

    /**
     * Validate that all pricing exists for the order items
     *
     * @return array Returns ['valid' => bool, 'errors' => array]
     */
    public function validatePricing(int $branchId, array $orderItems): array
    {
        $errors = [];

        foreach ($orderItems as $index => $item) {
            $itemErrors = [];

            // Validate service price
            try {
                $this->calculateServicePiecePrice($branchId, $item['service_id'], $item['piece_id']);
            } catch (\Exception $e) {
                $itemErrors[] = $e->getMessage();
            }

            // Validate additional service prices
            if (isset($item['additional_services']) && is_array($item['additional_services'])) {
                foreach ($item['additional_services'] as $additionIndex => $addition) {
                    try {
                        $this->calculateAdditionalServicePrice(
                            $branchId,
                            $item['piece_id'],
                            $addition['service_addition_id']
                        );
                    } catch (\Exception $e) {
                        $itemErrors[] = "Additional service {$additionIndex}: ".$e->getMessage();
                    }
                }
            }

            if (! empty($itemErrors)) {
                $errors["item_{$index}"] = $itemErrors;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
