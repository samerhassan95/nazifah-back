<?php

namespace Modules\Order\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'vendor_id' => $this->vendor_id,
            'driver_id' => $this->driver_id,
            'pickup_driver_id' => $this->pickup_driver_id,
            'delivery_driver_id' => $this->delivery_driver_id,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_methods' => $this->resolvedPaymentMethods(),
            'payment_status' => $this->payment_status,
            'payment_status_label' => \App\Support\PaymentStatusPresenter::label($this->payment_status ?? 'pending'),
            'rating' => $this->rating !== null ? (int) $this->rating : null,
            'review' => $this->review,
            ...$this->clientVisitResponseFields(),
            'total_amount' => $this->total_amount,
            'discount_amount' => $this->discount_amount,
            ...$this->couponResponseFields(),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
