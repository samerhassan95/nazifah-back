<?php

namespace Modules\Invoice\Services\Providers;

use GuzzleHttp\Client;
use Modules\Invoice\Contracts\WhatsappInvoiceGatewayInterface;
use Modules\Invoice\DTOs\WhatsappDeliveryResult;
use Modules\Invoice\Models\Invoice;

class HttpWhatsappGateway implements WhatsappInvoiceGatewayInterface
{
    public function sendInvoiceLink(Invoice $invoice, array $payload): WhatsappDeliveryResult
    {
        $baseUrl = rtrim((string) config('invoice.whatsapp.base_url'), '/');
        $path = '/'.ltrim((string) config('invoice.whatsapp.send_path', '/messages'), '/');

        try {
            $client = new Client([
                'base_uri' => $baseUrl,
                'timeout' => (int) config('invoice.whatsapp.timeout', 20),
            ]);

            $response = $client->post($path, [
                'headers' => array_filter([
                    'Accept' => 'application/json',
                    'Authorization' => config('invoice.whatsapp.api_key') ? 'Bearer '.config('invoice.whatsapp.api_key') : null,
                ]),
                'json' => $payload,
            ]);

            $decoded = json_decode((string) $response->getBody(), true) ?: [];

            return new WhatsappDeliveryResult(
                success: $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
                status: (string) ($decoded['status'] ?? 'sent'),
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
