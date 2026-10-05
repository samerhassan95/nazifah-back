<?php

namespace Modules\Invoice\Services\Providers;

use GuzzleHttp\Client;
use Modules\Invoice\Contracts\WhatsappInvoiceGatewayInterface;
use Modules\Invoice\DTOs\WhatsappDeliveryResult;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Services\InvoiceSettingsService;

class HttpWhatsappGateway implements WhatsappInvoiceGatewayInterface
{
    public function __construct(
        private ?InvoiceSettingsService $settings = null
    ) {
        $this->settings ??= app(InvoiceSettingsService::class);
    }

    public function sendInvoiceLink(Invoice $invoice, array $payload): WhatsappDeliveryResult
    {
        $baseUrl = rtrim((string) $this->settings->get('invoice_whatsapp_base_url', ''), '/');
        $path = '/'.ltrim((string) $this->settings->get('invoice_whatsapp_send_path', '/messages'), '/');

        try {
            $client = new Client([
                'base_uri' => $baseUrl,
                'timeout' => (int) $this->settings->get('invoice_whatsapp_timeout', 20),
            ]);

            $response = $client->post($path, [
                'headers' => array_filter([
                    'Accept' => 'application/json',
                    'Authorization' => $this->settings->get('invoice_whatsapp_api_key')
                        ? 'Bearer '.$this->settings->get('invoice_whatsapp_api_key')
                        : null,
                ]),
                'json' => $payload,
            ]);

            $decoded = json_decode((string) $response->getBody(), true) ?: [];

            return new WhatsappDeliveryResult(
                success: $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
                // The provider only accepted the message here; delivery and read states
                // arrive later via status webhooks, so never record "delivered" by default.
                status: (string) ($decoded['status'] ?? 'accepted'),
                providerMessageId: $decoded['message_id'] ?? null,
                requestPayload: $payload,
                responsePayload: $decoded,
            );
        } catch (\Throwable $e) {
            return new WhatsappDeliveryResult(
                success: false,
                status: 'failed',
                requestPayload: $payload,
                errorMessage: $e->getMessage(),
            );
        }
    }
}
