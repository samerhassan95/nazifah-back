<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Modules\Order\Models\Order;
use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException
{
    public readonly OrderStatus $from;

    public readonly OrderStatus $to;

    public readonly ?Order $order;

    public function __construct(OrderStatus $from, OrderStatus $to, ?Order $order = null)
    {
        $this->from = $from;
        $this->to = $to;
        $this->order = $order;

        $orderId = $order?->id ?? 'N/A';
        parent::__construct(
            "Invalid status transition from '{$from->value}' to '{$to->value}' for order #{$orderId}"
        );
    }

    /**
     * End-user friendly message (no internal status keys or order ids).
     */
    public function userMessage(): string
    {
        return __('order.invalid_status_transition', [
            'from' => $this->from->localizedLabel(),
            'to' => $this->to->localizedLabel(),
        ]);
    }
}
