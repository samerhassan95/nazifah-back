<?php

namespace Modules\Invoice\Services\Providers;

use Modules\Invoice\Contracts\WhatsappInvoiceGatewayInterface;
use Modules\Invoice\DTOs\WhatsappDeliveryResult;
use Modules\Invoice\Models\Invoice;

class MockWhatsappGateway implements WhatsappInvoiceGatewayInterface
{
    public function sendInvoiceLink(Invoice $invoice, array $payload): WhatsappDeliveryResult
    {
        return new WhatsappDeliveryResult(
            success: true,
            status: 'sent',
            providerMessageId: 'MOCK-WA-'.$invoice->id,
            requestPayload: $payload,
            responsePayload: ['mock' => true, 'sent' => true],
        );
    }
}
