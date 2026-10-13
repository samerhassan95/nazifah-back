<?php

namespace Modules\Order\Services;

use App\Services\OrderCatalogAvailabilityService;
use App\Services\UploadFilesService;
use App\Support\OrderItemDisplayNames;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Client\Models\Client;
use Modules\Order\Models\Cart;
use Modules\Order\Models\CartItem;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;

/**
 * Server-side cart: the backend is the source of truth once an item is added
 * successfully. See E:\nazifah\CART_BACKEND_API.md for the full spec this
 * implements.
 *
 * Prices are never stored on a cart line — every read resolves them live
 * from service_piece / service_addition_piece (same tables the rest of the
 * catalog/order system already uses), so a price change shows up on the
 * next GET without needing to touch the cart row.
 */
class CartService
{
    public function __construct(
        private OrderCatalogAvailabilityService $availability,
        private UploadFilesService $uploadFilesService,
    ) {}

    public function getOrCreateCart(Client $client): Cart
    {
        return Cart::firstOrCreate(['client_id' => $client->id]);
    }

    /**
     * @return array{success: bool, status: int, message?: string, data?: array, conflict?: array}
     */
    public function addItems(Client $client, int $branchId, array $items, string $lang): array
    {
        $branch = Branch::find($branchId);
        if (! $branch) {
            return ['success' => false, 'status' => 404, 'message' => __('cart.branch_not_found')];
        }
        if (! $branch->is_active) {
            return ['success' => false, 'status' => 400, 'message' => __('cart.branch_not_active')];
        }

        $cart = $this->getOrCreateCart($client);
        $hasExistingItems = $cart->items()->exists();

        if ($hasExistingItems && $cart->branch_id && (int) $cart->branch_id !== $branchId) {
            return [
                'success' => false,
                'status' => 409,
                'message' => __('cart.branch_mismatch'),
                'conflict' => [
                    'current_branch_id' => (int) $cart->branch_id,
                    'requested_branch_id' => $branchId,
                ],
            ];
        }

        // Validate every line before writing any of them — a partially-added
        // request would leave the cart in a state the client never asked for.
        foreach ($items as $item) {
            $serviceIds = $this->normalizeIds($item['service_ids'] ?? []);
            if ($serviceIds === []) {
                return ['success' => false, 'status' => 400, 'message' => __('cart.service_ids_required')];
            }

            foreach ($serviceIds as $serviceId) {
                $error = $this->availability->validateOrderLineForNewOrder(
                    $branchId,
                    (int) $item['piece_id'],
                    $serviceId,
                    $this->normalizeIds($item['additional_service_ids'] ?? []),
                    $lang
                );
                if ($error !== null) {
                    return ['success' => false, 'status' => 400, 'message' => $error];
                }
            }
        }

        $addedItemIds = [];

        DB::transaction(function () use ($cart, $branchId, $items, &$addedItemIds) {
            if (! $cart->branch_id) {
                $cart->update(['branch_id' => $branchId]);
            }

            foreach ($items as $item) {
                $cartItem = CartItem::create([
                    'cart_id' => $cart->id,
                    'piece_id' => $item['piece_id'],
                    'service_ids' => $this->normalizeIds($item['service_ids'] ?? []),
                    'additional_service_ids' => $this->normalizeIds($item['additional_service_ids'] ?? []),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'note' => $item['note'] ?? null,
                    'image' => $item['image'] ?? null,
                ]);
                $addedItemIds[] = $cartItem->id;
            }
        });

        $data = $this->formatCart($cart->fresh(), $lang);
        $data['added_item_ids'] = $addedItemIds;

        return ['success' => true, 'status' => 200, 'data' => $data];
    }

    /**
     * @return array{success: bool, status: int, message?: string, data?: array}
     */
    public function updateItem(Client $client, int $cartItemId, array $changes, string $lang): array
    {
        $cartItem = $this->findOwnedItem($client, $cartItemId);
        if (! $cartItem) {
            return ['success' => false, 'status' => 404, 'message' => __('cart.item_not_found')];
        }

        $cart = $cartItem->cart;
        $branchId = (int) $cart->branch_id;

        $serviceIds = array_key_exists('service_ids', $changes)
            ? $this->normalizeIds($changes['service_ids'])
            : $cartItem->service_ids;
        $additionalServiceIds = array_key_exists('additional_service_ids', $changes)
            ? $this->normalizeIds($changes['additional_service_ids'])
            : $cartItem->additional_service_ids;

        if ($serviceIds === []) {
            return ['success' => false, 'status' => 400, 'message' => __('cart.service_ids_required')];
        }

        foreach ($serviceIds as $serviceId) {
            $error = $this->availability->validateOrderLineForNewOrder(
                $branchId,
                (int) $cartItem->piece_id,
                $serviceId,
                $additionalServiceIds,
                $lang
            );
            if ($error !== null) {
                return ['success' => false, 'status' => 400, 'message' => $error];
            }
        }

        $cartItem->update([
            'service_ids' => $serviceIds,
            'additional_service_ids' => $additionalServiceIds,
            'quantity' => array_key_exists('quantity', $changes) ? max(1, (int) $changes['quantity']) : $cartItem->quantity,
            'note' => array_key_exists('note', $changes) ? $changes['note'] : $cartItem->note,
            'image' => array_key_exists('image', $changes) ? $changes['image'] : $cartItem->image,
        ]);

        return ['success' => true, 'status' => 200, 'data' => $this->formatCart($cart->fresh(), $lang)];
    }

    public function deleteItem(Client $client, int $cartItemId): bool
    {
        $cartItem = $this->findOwnedItem($client, $cartItemId);
        if (! $cartItem) {
            return false;
        }

        $cart = $cartItem->cart;
        $cartItem->delete();

        if (! $cart->items()->exists()) {
            $cart->update(['branch_id' => null]);
        }

        return true;
    }

    public function clear(Client $client): void
    {
        $cart = $this->getOrCreateCart($client);
        $cart->items()->delete();
        $cart->update(['branch_id' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatCart(Cart $cart, string $lang): array
    {
        $branch = $cart->branch_id ? Branch::with('vendor')->find($cart->branch_id) : null;
        $items = [];
        $unavailableItems = [];
        $subtotal = 0.0;

        foreach ($cart->items()->with('piece.iconRelation')->get() as $cartItem) {
            $formatted = $this->formatCartItem($cartItem, $branch, $lang);
            if ($formatted['is_available']) {
                $items[] = $formatted;
                $subtotal += $formatted['line_total'];
            } else {
                $unavailableItems[] = $formatted;
            }
        }

        return [
            'cart_id' => $cart->id,
            'branch_id' => $cart->branch_id,
            'laundry_id' => $branch?->vendor_id,
            'laundry' => $branch?->vendor ? [
                'id' => $branch->vendor->id,
                'name' => $branch->vendor->getTranslation('name', $lang),
                'logo' => $this->uploadFilesService->getFullUrl($branch->vendor->logo ?? null),
            ] : null,
            'items' => $items,
            'unavailable_items' => $unavailableItems,
            'summary' => [
                'items_count' => count($items),
                'subtotal' => round($subtotal, 2),
                'currency' => config('payment.currency', 'SAR'),
            ],
            'has_unavailable_items' => $unavailableItems !== [],
            'can_checkout' => $unavailableItems === [] && $items !== [],
        ];
    }

    /**
     * Cart items converted into the exact shape OrderController's
     * calculate()/store() already validate ('items.*.piece_id' etc.), for
     * when the client doesn't send its own `items` array and the backend
     * must read the cart instead (CART_BACKEND_API.md §6).
     *
     * Cart item images are stored paths, not fresh uploads, and order
     * creation's file-upload validation expects a real file — images are
     * intentionally left out here; the client re-attaches them at checkout
     * if the order endpoint requires one.
     *
     * @return array{items: array, branch_id: int}|array{error: string}
     */
    public function itemsForOrderCreation(Client $client, string $lang): array
    {
        $cart = $this->getOrCreateCart($client);
        $cartItems = $cart->items()->get();

        if ($cartItems->isEmpty()) {
            return ['error' => __('cart.cart_empty')];
        }

        $formatted = $this->formatCart($cart, $lang);
        if ($formatted['has_unavailable_items']) {
            return ['error' => __('cart.cart_has_unavailable_items')];
        }

        $items = $cartItems->map(fn (CartItem $item) => [
            'piece_id' => $item->piece_id,
            'quantity' => $item->quantity,
            'service_ids' => $item->service_ids,
            'additional_service_ids' => $item->additional_service_ids ?? [],
            'note' => $item->note,
        ])->all();

        return ['items' => $items, 'branch_id' => (int) $cart->branch_id];
    }

    private function formatCartItem(CartItem $cartItem, ?Branch $branch, string $lang): array
    {
        $piece = $cartItem->piece;
        $branchId = $branch?->id;
        $serviceIds = $cartItem->service_ids ?? [];
        $additionalServiceIds = $cartItem->additional_service_ids ?? [];

        $unavailable = $this->unavailabilityReason($piece, $branch, $serviceIds, $additionalServiceIds, $lang);

        $serviceNames = [];
        $unitPrice = 0.0;
        $additionalServices = [];

        if ($unavailable === null && $piece && $branchId) {
            foreach ($serviceIds as $serviceId) {
                $service = Service::find($serviceId);
                if (! $service) {
                    continue;
                }
                $serviceNames[] = OrderItemDisplayNames::serviceName($service, $branchId, $lang);
                $unitPrice += (float) $service->getPriceForPieceAtBranch($piece->id, $branchId);
            }

            foreach ($additionalServiceIds as $additionId) {
                $addition = ServiceAddition::find($additionId);
                if (! $addition) {
                    continue;
                }
                $price = (float) $addition->getPriceForPieceAtBranch($piece->id, $branchId);
                $unitPrice += $price;
                $additionalServices[] = [
                    'id' => $addition->id,
                    'name' => OrderItemDisplayNames::additionalServiceName($addition, $branchId, $lang),
                    'price' => $price,
                ];
            }
        } elseif ($piece) {
            // Still show names for an unavailable line so the UI can render it meaningfully.
            foreach ($serviceIds as $serviceId) {
                $service = Service::find($serviceId);
                if ($service) {
                    $serviceNames[] = $service->getTranslation('service_name', $lang);
                }
            }
        }

        $pieceName = $piece
            ? ($branchId ? OrderItemDisplayNames::pieceName($piece, $branchId, $lang) : $piece->getTranslation('name', $lang))
            : null;

        return [
            'cart_item_id' => $cartItem->id,
            'piece_id' => $cartItem->piece_id,
            'piece_name' => $pieceName,
            'piece_icon' => $piece ? $this->uploadFilesService->getFullUrl($piece->iconRelation?->full_path ?? $piece->iconRelation?->path) : null,
            'service_ids' => $serviceIds,
            'service_names' => $serviceNames,
            'quantity' => $cartItem->quantity,
            'additional_service_ids' => $additionalServiceIds,
            'additional_services' => $additionalServices,
            'note' => $cartItem->note,
            'image' => $cartItem->image ? $this->uploadFilesService->getFullUrl($cartItem->image) : null,
            'unit_price' => round($unitPrice, 2),
            'line_total' => round($unitPrice * $cartItem->quantity, 2),
            'is_available' => $unavailable === null,
            'unavailable_reason' => $unavailable,
            'unavailable_reason_code' => $unavailable !== null ? $this->unavailableReasonCode($piece, $branch, $serviceIds) : null,
        ];
    }

    private function unavailabilityReason(?Piece $piece, ?Branch $branch, array $serviceIds, array $additionalServiceIds, string $lang): ?string
    {
        if (! $branch) {
            return __('cart.reason_branch_closed');
        }
        if (! $branch->is_active) {
            return __('cart.reason_branch_closed');
        }
        if (! $piece) {
            return __('cart.reason_piece_unavailable');
        }
        if (! $this->availability->isPieceActiveAtBranch($piece->id, $branch->id)) {
            return __('cart.reason_piece_unavailable');
        }
        foreach ($serviceIds as $serviceId) {
            if (! $this->availability->isServiceActiveAtBranch((int) $serviceId, $branch->id)) {
                return __('cart.reason_service_unavailable');
            }
        }
        foreach ($additionalServiceIds as $additionId) {
            if (! $this->availability->isServiceAdditionActiveForPieceAtBranch((int) $additionId, $piece->id, $branch->id)) {
                return __('cart.reason_addon_unavailable');
            }
        }

        return null;
    }

    private function unavailableReasonCode(?Piece $piece, ?Branch $branch, array $serviceIds): string
    {
        if (! $branch || ! $branch->is_active) {
            return 'branch_closed';
        }
        if (! $piece || ! $this->availability->isPieceActiveAtBranch($piece->id, $branch->id)) {
            return 'piece_unavailable';
        }
        foreach ($serviceIds as $serviceId) {
            if (! $this->availability->isServiceActiveAtBranch((int) $serviceId, $branch->id)) {
                return 'service_unavailable';
            }
        }

        return 'other';
    }

    private function findOwnedItem(Client $client, int $cartItemId): ?CartItem
    {
        return CartItem::query()
            ->whereHas('cart', fn ($q) => $q->where('client_id', $client->id))
            ->find($cartItemId);
    }

    /**
     * @return list<int>
     */
    private function normalizeIds($ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
