<?php

namespace Modules\Admin\Http\Resources;

use App\Enums\OrderStatus;
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
        $isAr = $locale === 'ar';
        $branchId = (int) ($this->branch_id ?? 0);

        $normalizedStatus = $this->status ?? 'pending';

        $pickupSelf = (bool) $this->pickup_at_vendor;
        $deliverySelf = (bool) $this->delivery_at_vendor;
        $addressModel = $this->deliveryAddress ?? $this->pickupAddress;

        $statusLabel = OrderStatus::fromString($normalizedStatus)?->localizedLabel(
            $this->payment_method,
            $normalizedStatus === OrderStatus::COMPLETED->value && ! $this->client_delivery_handoff_at,
            (bool) $this->delivery_at_vendor,
            false,
        ) ?? $normalizedStatus;

        $clientName = $this->client ? ($this->client->getTranslation('full_name', 'ar') ?? $this->client->full_name ?? 'N/A') : 'N/A';
        $driverName = $this->driver ? ($this->driver->getTranslation('full_name', 'ar') ?? $this->driver->full_name ?? 'N/A') : 'N/A';
        $branchName = $this->branch ? ($this->branch->getTranslation('name', 'ar') ?? $this->branch->name ?? 'N/A') : 'N/A';
        $vendorName = $vendor ? ($vendor->getTranslation('name', 'ar') ?? $vendor->name ?? 'N/A') : 'N/A';
        $piecesCount = $this->relationLoaded('items') ? $this->items->count() : $this->items()->count();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'Order_code' => $this->order_number,
            'order_code' => $this->order_number,
            'client_id' => $this->client_id,
            'branch_id' => $this->branch_id,
            'vendor_id' => $this->branch?->vendor_id,
            'driver_id' => $this->driver_id,
            'pickup_driver_id' => $this->pickup_driver_id,
            'delivery_driver_id' => $this->delivery_driver_id,

            'status' => $this->status,
            'Order_status' => $normalizedStatus,
            'order_status' => $normalizedStatus,
            'status_label' => $statusLabel,

            'payment_status' => $this->latestPayment?->status ?? 'pending',
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($this->latestPayment?->status ?? 'pending'),
            'payment_method' => $this->payment_method,

            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'delivery_fee' => $this->delivery_fee,
            'discount_amount' => $this->discount_amount,
            ...$this->couponResponseFields(),

            'total_price' => (float) $this->final_amount,
            'Order_value' => (float) $this->final_amount,
            'order_value' => (float) $this->final_amount,

            'Order_date' => $this->created_at ? $this->created_at->format('d M Y') : null,
            'order_date' => $this->created_at ? $this->created_at->format('d M Y') : null,

            'Client_name' => $clientName,
            'client_name' => $clientName,
            'Driver_name' => $driverName,
            'driver_name' => $driverName,
            'Pieces_count' => $piecesCount,
            'pieces_count' => $piecesCount,
            'Branch' => $branchName,
            'branch_name' => $branchName,
            'Laundry_name' => $vendorName,
            'laundry_name' => $vendorName,

            'Distance' => (float) ($this->distance ?? 0),
            'distance' => (float) ($this->distance ?? 0),
            'Pickup_distance' => (float) ($this->pickup_distance ?? 0),
            'Delivery_distance' => (float) ($this->delivery_distance ?? 0),

            'Pickup_at_vendor' => $pickupSelf,
            'pickup_at_vendor' => $pickupSelf,
            'Delivery_at_vendor' => $deliverySelf,
            'delivery_at_vendor' => $deliverySelf,

            'Pickup_method' => [
                'type' => $pickupSelf ? 'self' : 'driver',
                'label' => $pickupSelf
                    ? ($isAr ? 'توصيل ذاتي إلى المغسلة' : 'Self drop-off at the laundry')
                    : ($isAr ? 'استلام من المنزل' : 'Pickup from home'),
            ],
            'Delivery_method' => [
                'type' => $deliverySelf ? 'self' : 'driver',
                'label' => $deliverySelf
                    ? ($isAr ? 'استلام ذاتي من المغسلة' : 'Self pickup from the laundry')
                    : ($isAr ? 'توصيل إلى المنزل' : 'Delivery to home'),
            ],

            'Address' => $addressModel?->street_name ?? $addressModel?->address_text ?? null,
            'address' => $addressModel?->street_name ?? $addressModel?->address_text ?? null,
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
