<?php

namespace Modules\Invoice\DTOs;

class WhatsappDeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?array $requestPayload = null,
        public readonly ?array $responsePayload = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
