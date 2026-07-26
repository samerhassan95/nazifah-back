<?php

namespace Modules\Payment\DTOs;

class CaptureRequest
{
    public function __construct(
        public readonly string $fortId,
        public readonly float $amount,
        public readonly string $merchantReference,
    ) {}

    public function toArray(): array
    {
        return [
            'fort_id' => $this->fortId,
            'amount' => $this->amount,
            'merchant_reference' => $this->merchantReference,
        ];
    }
}
