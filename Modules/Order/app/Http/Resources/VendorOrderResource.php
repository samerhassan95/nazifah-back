<?php

namespace Modules\Order\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Support\OrderItemGrouper;

class VendorOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $uploadService = app(UploadFilesService::class);
        $locale = app()->getLocale();

        // Title logic
        $firstItem = $this->items->first();
        $orderTitle = 'Order';
        if ($firstItem && $firstItem->piece) {
            $orderTitle = \App\Support\OrderItemDisplayNames::pieceName(
                $firstItem->piece,
                (int) ($this->branch_id ?? 0),
                $locale
            ) ?: 'Order';
        }

        $branchLocation = $this->branch ? $this->branch->getApiLocation($locale) : null;

        // Image logic
        $firstItemImage = null;
        $itemWithImage = $this->items->first(fn ($item) => ! empty($item->images));
        if ($itemWithImage) {
            $firstItemImage = $uploadService->getFullUrl($itemWithImage->images);
        }

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_title' => $orderTitle,
            'branch_id' => $this->branch_id,
            'client_name' => $this->client?->full_name ?? 'Customer',
            'driver_name' => $this->driver?->full_name,
            'pickup_address' => $this->pickupAddress?->street_name ?? $this->pickupAddress?->address_line_1,
            'number_pieces' => OrderItemGrouper::totalPiecesCount($this->items),
            'distance' => (float) ($this->distance ?? 0),
            'first_item_image' => $firstItemImage,
            'branch_location' => $branchLocation,
            'delivery_price' => (float) $this->delivery_fee,
            'status' => $this->status,
            'status_label' => $this->status_label,
            ...$this->couponResponseFields($locale),
            ...$this->clientVisitResponseFields(),
            'rating' => $this->rating !== null ? (int) $this->rating : null,
            'review' => $this->review,
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
