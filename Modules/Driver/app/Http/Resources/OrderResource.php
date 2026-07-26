<?php

namespace Modules\Driver\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $uploadService = app(UploadFilesService::class);
        $lang = app()->getLocale();

        return [
            'order_id' => $this->id,
            'order_number' => $this->order_number,
            'branch_id' => $this->branch_id,
            'driver_id' => $this->driver_id,
            'pickup_driver_id' => $this->pickup_driver_id,
            'delivery_driver_id' => $this->delivery_driver_id,
            'status' => $this->status,
            'status_text' => $this->getStatusText(),
            'vendor' => $this->when($this->vendor, [
                'id' => $this->vendor?->id,
                'name' => $this->vendor?->getTranslatedName($lang),
                'logo' => $uploadService->getFullUrl($this->vendor?->logo),
                'phone' => $this->vendor?->phone,
            ]),
            'branch' => $this->when($this->branch, array_merge(
                $this->branch->toApiOrderBranchFlat($lang),
                ['phone_number' => $this->branch->phone_number]
            )),
            'client' => $this->when($this->client, array_merge(
                $this->client->toApiClientInfo(
                    $lang,
                    $uploadService->getFullUrl($this->client?->image)
                ),
                ['phone' => $this->client?->phone]
            )),
            'pickup_at_vendor' => (bool) $this->pickup_at_vendor,
            'delivery_at_vendor' => (bool) $this->delivery_at_vendor,
            'pickup_address' => $this->when($this->pickupAddress, [
                'latitude' => (float) $this->pickupAddress?->latitude,
                'longitude' => (float) $this->pickupAddress?->longitude,
                'address_text' => $this->pickupAddress?->street_name,
                'building_number' => $this->pickupAddress?->building_number,
                'national_address' => $this->pickupAddress?->national_address,
                ...$this->pickupAddress->getApiFloorAttributes(),
            ]),
            'delivery_address' => $this->when($this->deliveryAddress, [
                'latitude' => (float) $this->deliveryAddress?->latitude,
                'longitude' => (float) $this->deliveryAddress?->longitude,
                'address_text' => $this->deliveryAddress?->street_name,
                'building_number' => $this->deliveryAddress?->building_number,
                'national_address' => $this->deliveryAddress?->national_address,
                ...$this->deliveryAddress->getApiFloorAttributes(),
            ]),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'total_amount' => (float) $this->total_amount,
            'discount_amount' => (float) $this->discount_amount,
            ...$this->couponResponseFields(),
            'tax_amount' => (float) $this->tax_amount,
            'delivery_fee' => (float) $this->delivery_fee,
            'final_amount' => (float) $this->final_amount,
            'distance' => $this->distance !== null ? (float) $this->distance : 0,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status ?? 'pending',
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($this->payment_status ?? 'pending'),
            'rating' => $this->rating !== null ? (int) $this->rating : null,
            'review' => $this->review,
            'notes' => $this->notes,
            ...$this->clientVisitResponseFields(),
            'qr_code' => $this->qr_code,
            'pickup_time' => $this->pickup_time?->toISOString(),
            'estimated_delivery_time' => $this->estimated_delivery_time?->toISOString(),
            'actual_delivery_time' => $this->actual_delivery_time?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }

    private function getStatusText(): string
    {
        return \App\Enums\OrderStatus::tryFrom($this->status)?->label() ?? 'Unknown';
    }
}
