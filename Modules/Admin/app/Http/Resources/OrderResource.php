<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Support\OrderItemGrouper;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Get vendor through branch relationship
        $vendor = $this->whenLoaded('branch', function () {
            return $this->branch?->vendor;
        });

        $locale = app()->getLocale();
        $branchId = (int) ($this->branch_id ?? 0);

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'client_id' => $this->client_id,
            'branch_id' => $this->branch_id,
            'vendor_id' => $this->branch?->vendor_id,
            'driver_id' => $this->driver_id,
            'pickup_driver_id' => $this->pickup_driver_id,
            'delivery_driver_id' => $this->delivery_driver_id,
            'status' => $this->status,
            'payment_status' => $this->latestPayment?->status ?? 'pending',
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($this->latestPayment?->status ?? 'pending'),
            'payment_method' => $this->payment_method,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'delivery_fee' => $this->delivery_fee,
            'discount_amount' => $this->discount_amount,
            ...$this->couponResponseFields(),
            'total_price' => $this->final_amount,
            'delivery_address' => $this->delivery_address,
            'notes' => $this->notes,
            'client' => new ClientResource($this->whenLoaded('client')),
            'branch' => $this->whenLoaded('branch'),
            'vendor' => $vendor ? new VendorResource($vendor) : null,
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'pickup_driver' => new DriverResource($this->whenLoaded('pickupDriver')),
            'delivery_driver' => new DriverResource($this->whenLoaded('deliveryDriver')),
            'items' => $this->whenLoaded('items', function () use ($branchId, $locale) {
                return OrderItemGrouper::toApiLines($this->items, $branchId, $locale);
            }),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
