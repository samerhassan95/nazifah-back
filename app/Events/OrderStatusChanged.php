<?php

namespace App\Events;

use App\Enums\OrderStatus;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Order\Models\Order;

class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $oldStatus,
        public readonly OrderStatus $newStatus,
        public readonly array $context = [],
    ) {}

    /**
     * Broadcast on the order's own channel (order details page) and a single
     * admin-wide channel (orders list/table) so both can drop their manual
     * refresh and just listen.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.'.$this->order->id),
            new PrivateChannel('admin.orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'status' => $this->newStatus->value,
            'status_label' => $this->newStatus->localizedLabel(
                $this->order->payment_method,
                $this->newStatus === OrderStatus::COMPLETED && ! $this->order->client_delivery_handoff_at,
                (bool) $this->order->delivery_at_vendor,
            ),
            'old_status' => $this->oldStatus->value,
        ];
    }
}
