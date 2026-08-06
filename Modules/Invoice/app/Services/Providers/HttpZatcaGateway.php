<?php

namespace Modules\Invoice\Services\Providers;

use GuzzleHttp\Client;
use Modules\Invoice\Contracts\InvoiceComplianceGatewayInterface;
use Modules\Invoice\DTOs\InvoiceComplianceResult;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Services\InvoiceSettingsService;

class HttpZatcaGateway implements InvoiceComplianceGatewayInterface
{
    public function __construct(
        private ?InvoiceSettingsService $settings = null
    ) {
        $this->settings ??= app(InvoiceSettingsService::class);
    }

    public function submitSimplifiedInvoice(Invoice $invoice, array $payload): InvoiceComplianceResult
    {
        $baseUrl = rtrim((string) $this->settings->get('invoice_zatca_base_url', ''), '/');
        $path = '/'.ltrim((string) $this->settings->get('invoice_zatca_submit_path', '/invoices'), '/');

        $requestPayload = [
            'environment' => $this->settings->get('invoice_zatca_environment', 'sandbox'),
            'invoice' => $payload,
        ];

        try {
            $client = new Client([
                'base_uri' => $baseUrl,
                'timeout' => (int) $this->settings->get('invoice_zatca_timeout', 20),
            ]);

            $response = $client->post($path, [
                'headers' => array_filter([
                    'Accept' => 'application/json',
                    'Authorization' => $this->settings->get('invoice_zatca_api_key')
                        ? 'Bearer '.$this->settings->get('invoice_zatca_api_key')
                        : null,
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
