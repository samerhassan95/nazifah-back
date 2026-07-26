<?php

namespace Modules\Payment\DTOs;

class RefundResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $refundId = null,
        public readonly ?string $status = null,
        public readonly ?float $amount = null,
        public readonly ?string $message = null,
        public readonly array $data = []
    ) {}

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'refund_id' => $this->refundId,
            'status' => $this->status,
            'amount' => $this->amount,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
