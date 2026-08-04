<?php

namespace Modules\Invoice\Services\Providers;

use GuzzleHttp\Client;
use Modules\Invoice\Contracts\InvoiceComplianceGatewayInterface;
use Modules\Invoice\DTOs\InvoiceComplianceResult;
use Modules\Invoice\Models\Invoice;

class HttpZatcaGateway implements InvoiceComplianceGatewayInterface
{
    public function submitSimplifiedInvoice(Invoice $invoice, array $payload): InvoiceComplianceResult
    {
        $baseUrl = rtrim((string) config('invoice.zatca.base_url'), '/');
        $path = '/'.ltrim((string) config('invoice.zatca.submit_path', '/invoices'), '/');

        $requestPayload = [
            'environment' => config('invoice.zatca.environment', 'sandbox'),
            'invoice' => $payload,
        ];

        try {
            $client = new Client([
                'base_uri' => $baseUrl,
                'timeout' => (int) config('invoice.zatca.timeout', 20),
            ]);

            $response = $client->post($path, [
                'headers' => array_filter([
                    'Accept' => 'application/json',
                    'Authorization' => config('invoice.zatca.api_key') ? 'Bearer '.config('invoice.zatca.api_key') : null,
                ]),
                'json' => $requestPayload,
            ]);

            $decoded = json_decode((string) $response->getBody(), true) ?: [];

            return new InvoiceComplianceResult(
                success: $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
                status: (string) ($decoded['status'] ?? 'reported'),
                reference: $decoded['reference'] ?? null,
                uuid: $decoded['uuid'] ?? null,
                invoiceHash: $decoded['invoice_hash'] ?? null,
                qrCode: $decoded['qr_code'] ?? null,
                requestPayload: $requestPayload,
                responsePayload: $decoded,
            );
        } catch (\Throwable $e) {
            return new InvoiceComplianceResult(
                success: false,
                status: 'failed',
                requestPayload: $requestPayload,
                errorMessage: $e->getMessage(),
            );
        }
    }
}
