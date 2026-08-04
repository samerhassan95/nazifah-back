<?php

namespace Modules\Invoice\DTOs;

class InvoiceComplianceResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly ?string $uuid = null,
        public readonly ?string $invoiceHash = null,
        public readonly ?string $qrCode = null,
        public readonly ?array $requestPayload = null,
        public readonly ?array $responsePayload = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
