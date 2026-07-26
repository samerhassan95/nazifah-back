<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use Illuminate\Support\Facades\Log;
use Modules\Order\Services\OrderPaymentService;

class HandleOrderCancellationRefund
{
    public function __construct(private OrderPaymentService $orderPaymentService) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->newStatus !== OrderStatus::CANCELLED) {
            return;
        }

        try {
            $this->orderPaymentService->refundOrderOnCancellation($event->order->fresh());
        } catch (\Throwable $e) {
            Log::error('HandleOrderCancellationRefund exception', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
