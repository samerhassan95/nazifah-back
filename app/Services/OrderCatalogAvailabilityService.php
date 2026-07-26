<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;

class OrderCatalogAvailabilityService
{
    public function isServiceActiveAtBranch(int $serviceId, int $branchId): bool
    {
        return DB::table('branch_service')
            ->join('services', 'services.id', '=', 'branch_service.service_id')
            ->where('branch_service.branch_id', $branchId)
            ->where('branch_service.service_id', $serviceId)
            ->where('branch_service.is_active', true)
            ->where('services.is_active', true)
            ->exists();
    }

    public function isPieceActiveAtBranch(int $pieceId, int $branchId): bool
    {
        return DB::table('branch_piece')
            ->join('pieces', 'pieces.id', '=', 'branch_piece.piece_id')
            ->where('branch_piece.branch_id', $branchId)
            ->where('branch_piece.piece_id', $pieceId)
            ->where('branch_piece.is_active', true)
            ->where('pieces.is_active', true)
            ->exists();
    }

    public function isServiceAdditionActiveForPieceAtBranch(int $serviceAdditionId, int $pieceId, int $branchId): bool
    {
        $addition = ServiceAddition::query()->find($serviceAdditionId);
        if (! $addition || ! $addition->is_active) {
            return false;
        }

        $branchOffering = DB::table('branch_service_addition')
            ->where('branch_id', $branchId)
            ->where('service_addition_id', $serviceAdditionId)
            ->first();

        if ($branchOffering && ! $branchOffering->is_active) {
            return false;
        }

        return DB::table('service_addition_piece')
            ->where('piece_id', $pieceId)
            ->where('service_addition_id', $serviceAdditionId)
            ->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            })
            ->exists();
    }

    /**
     * Validate a single order line for new order creation. Returns error message or null.
     */
    public function validateOrderLineForNewOrder(
        int $branchId,
        int $pieceId,
        int $serviceId,
        array $additionalServiceIds,
        string $lang
    ): ?string {
        $piece = Piece::find($pieceId);
        if (! $piece) {
            return __('order.piece_not_found');
        }

        if (! $this->isPieceActiveAtBranch($pieceId, $branchId)) {
            $pieceName = method_exists($piece, 'getTranslation')
                ? $piece->getTranslation('name', $lang)
                : ($piece->name ?? 'Item');

            return __('order.piece_not_active_for_order', ['piece_name' => $pieceName]);
        }

        $service = Service::find($serviceId);
        if (! $service) {
            return __('order.service_not_available', [
                'piece_name' => method_exists($piece, 'getTranslation')
                    ? $piece->getTranslation('name', $lang)
                    : ($piece->name ?? 'Item'),
            ]);
        }

        if (! $this->isServiceActiveAtBranch($serviceId, $branchId)) {
            $serviceName = method_exists($service, 'getTranslation')
                ? $service->getTranslation('service_name', $lang)
                : ($service->service_name ?? 'Service');

            return __('order.service_not_active_for_order', ['service_name' => $serviceName]);
        }

        $pieceHasService = $piece->services()->where('services.id', $serviceId)->exists();
        if (! $pieceHasService) {
            $pieceName = method_exists($piece, 'getTranslation')
                ? $piece->getTranslation('name', $lang)
                : ($piece->name ?? 'Item');

            return __('order.service_not_available', ['piece_name' => $pieceName]);
        }

        foreach (array_unique($additionalServiceIds) as $additionalServiceId) {
            if (! $this->isServiceAdditionActiveForPieceAtBranch((int) $additionalServiceId, $pieceId, $branchId)) {
                $addition = ServiceAddition::find($additionalServiceId);
                $additionName = $addition
                    ? (method_exists($addition, 'getTranslation')
                        ? $addition->getTranslation('name', $lang)
                        : ($addition->name ?? "ID: {$additionalServiceId}"))
                    : "ID: {$additionalServiceId}";

                return __('order.additional_service_not_active_for_order', ['service_name' => $additionName]);
            }
        }

        return null;
    }

    /**
     * Validate an order line during edit. Lines already on the order may stay even if
     * the vendor later toggled them off the public catalog; new/changed lines use the
     * full new-order rules.
     */
    public function validateOrderLineForOrderUpdate(
        int $branchId,
        int $pieceId,
        int $serviceId,
        array $additionalServiceIds,
        string $lang,
        bool $lineExistedOnOrder
    ): ?string {
        if (! $lineExistedOnOrder) {
            return $this->validateOrderLineForNewOrder(
                $branchId,
                $pieceId,
                $serviceId,
                $additionalServiceIds,
                $lang
            );
        }

        $piece = Piece::withTrashed()->find($pieceId);
        if (! $piece || $piece->trashed()) {
            return __('order.piece_not_found');
        }

        $service = Service::find($serviceId);
        if (! $service) {
            return __('order.service_not_available', [
                'piece_name' => method_exists($piece, 'getTranslation')
                    ? $piece->getTranslation('name', $lang)
                    : ($piece->name ?? 'Item'),
            ]);
        }

        if (! $piece->services()->where('services.id', $serviceId)->exists()) {
            $pieceName = method_exists($piece, 'getTranslation')
                ? $piece->getTranslation('name', $lang)
                : ($piece->name ?? 'Item');

            return __('order.service_not_available', ['piece_name' => $pieceName]);
        }

        foreach (array_unique($additionalServiceIds) as $additionalServiceId) {
            if (! $this->isServiceAdditionActiveForPieceAtBranch((int) $additionalServiceId, $pieceId, $branchId)) {
                $addition = ServiceAddition::find($additionalServiceId);
                $additionName = $addition
                    ? (method_exists($addition, 'getTranslation')
                        ? $addition->getTranslation('name', $lang)
                        : ($addition->name ?? "ID: {$additionalServiceId}"))
                    : "ID: {$additionalServiceId}";

                return __('order.additional_service_not_active_for_order', ['service_name' => $additionName]);
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{piece_id: int, service_id: int, additional_service_ids?: int[]}>  $items
     */
    public function validateOrderItemsForNewOrder(array $items, int $branchId, string $lang): ?string
    {
        $branch = Branch::find($branchId);
        if (! $branch) {
            return __('branch.not_found');
        }

        foreach ($items as $item) {
            $error = $this->validateOrderLineForNewOrder(
                $branchId,
                (int) $item['piece_id'],
                (int) $item['service_id'],
                $item['additional_service_ids'] ?? [],
                $lang
            );

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }
}
