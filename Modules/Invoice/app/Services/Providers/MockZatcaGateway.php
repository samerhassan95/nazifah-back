<?php

namespace Modules\Invoice\Services\Providers;

use Modules\Invoice\Contracts\InvoiceComplianceGatewayInterface;
use Modules\Invoice\DTOs\InvoiceComplianceResult;
use Modules\Invoice\Models\Invoice;

class MockZatcaGateway implements InvoiceComplianceGatewayInterface
{
    public function submitSimplifiedInvoice(Invoice $invoice, array $payload): InvoiceComplianceResult
    {
        $response = [
            'mock' => true,
            'invoice_number' => $invoice->invoice_number,
            'accepted' => true,
        ];

        return new InvoiceComplianceResult(
            success: true,
            status: 'reported',
            reference: 'MOCK-'.$invoice->invoice_number,
            uuid: (string) \Illuminate\Support\Str::uuid(),
            invoiceHash: hash('sha256', json_encode($payload)),
            qrCode: base64_encode($invoice->invoice_number.'|'.($payload['total_amount'] ?? '0')),
            requestPayload: $payload,
            responsePayload: $response,
        );
    }
}
