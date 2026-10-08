<?php

namespace Modules\Invoice\Services\Providers;

use GuzzleHttp\Client;
use Modules\Invoice\Contracts\WhatsappInvoiceGatewayInterface;
use Modules\Invoice\DTOs\WhatsappDeliveryResult;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Services\InvoiceSettingsService;

/**
 * Sends invoice WhatsApp messages through a Twilio approved Content Template.
 *
 * Twilio identifies the template by a Content SID (starts with "HX"), not a
 * name — that SID is an admin-configurable setting per use case
 * (invoice_whatsapp_twilio_content_sid today; a second/third notification
 * type gets its own settings key and its own call site, each resolving its
 * own ContentSid). Account credentials (SID, Auth Token, WhatsApp-enabled
 * number) live in config/twilio.php, sourced from .env — same split as
 * DeewanSmsService (core credentials in .env, per-feature settings in the
 * DB-backed admin settings).
 */
class TwilioWhatsappGateway implements WhatsappInvoiceGatewayInterface
{
    public function __construct(
        private ?InvoiceSettingsService $settings = null
    ) {
        $this->settings ??= app(InvoiceSettingsService::class);
    }

    public function sendInvoiceLink(Invoice $invoice, array $payload): WhatsappDeliveryResult
    {
        $accountSid = (string) config('twilio.account_sid');
        $authToken = (string) config('twilio.auth_token');
        $from = (string) config('twilio.whatsapp_from');
        $contentSid = (string) $this->settings->get('invoice_whatsapp_twilio_content_sid', '');

        if ($accountSid === '' || $authToken === '' || $from === '') {
            return new WhatsappDeliveryResult(
                success: false,
                status: 'failed',
                requestPayload: $payload,
                errorMessage: 'Twilio is not configured: set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_WHATSAPP_FROM in .env.'
            );
        }

        if ($contentSid === '') {
            return new WhatsappDeliveryResult(
                success: false,
                status: 'failed',
                requestPayload: $payload,
                errorMessage: 'Twilio Content SID is not configured (invoice_whatsapp_twilio_content_sid admin setting).'
            );
        }

        $to = $this->normalizePhone((string) ($payload['to'] ?? ''));
        if ($to === '') {
            return new WhatsappDeliveryResult(
                success: false,
                status: 'failed',
                requestPayload: $payload,
                errorMessage: 'No recipient phone number.'
            );
        }

        // Twilio Content Templates take numbered placeholders ("1", "2", ...), not named
        // ones — the order here MUST match the order the template was built with in the
        // Twilio Console / Content API. Document the order you use there.
        $variables = $payload['variables'] ?? [];
        $contentVariables = [
            '1' => (string) ($variables['customer_name'] ?? ''),
            '2' => (string) ($variables['order_number'] ?? ''),
            '3' => (string) ($variables['invoice_number'] ?? ''),
            '4' => (string) ($variables['amount'] ?? ''),
            '5' => (string) ($variables['invoice_url'] ?? ''),
        ];

        $form = [
            'From' => 'whatsapp:'.ltrim($from, '+'),
            'To' => 'whatsapp:'.$to,
            'ContentSid' => $contentSid,
            'ContentVariables' => json_encode($contentVariables),
        ];
        // Twilio does not document whether a document/PDF header on a Content Template
        // is filled from a per-message MediaUrl or only from the template's own Console
        // config — and there is no PDF file generated for invoices yet (only an HTML
        // share link). Leaving MediaUrl out until both are confirmed; see the invoice
        // WhatsApp attachment question raised separately.

        try {
            $client = new Client(['timeout' => (int) $this->settings->get('invoice_whatsapp_timeout', 20)]);

            $response = $client->post(
                "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json",
                [
                    'auth' => [$accountSid, $authToken],
                    'form_params' => $form,
                ]
            );

            $decoded = json_decode((string) $response->getBody(), true) ?: [];
            $status = (string) ($decoded['status'] ?? 'accepted');
            // Twilio reports failures with HTTP 2xx too (status: "failed"/"undelivered").
            $success = $response->getStatusCode() >= 200
                && $response->getStatusCode() < 300
                && ! in_array($status, ['failed', 'undelivered'], true);

            return new WhatsappDeliveryResult(
                success: $success,
                status: $status,
                providerMessageId: $decoded['sid'] ?? null,
                requestPayload: $form,
                responsePayload: $decoded,
                errorMessage: $success ? null : (string) ($decoded['error_message'] ?? $decoded['message'] ?? null),
            );
        } catch (\Throwable $e) {
            return new WhatsappDeliveryResult(
                success: false,
                status: 'failed',
                requestPayload: $form,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * E.164, no "whatsapp:" prefix (added by the caller) — e.g. 0501234567 -> 966501234567.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '966')) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+966'.substr($digits, 1);
        }
        if ($digits !== '') {
            return '+'.$digits;
        }

        return '';
    }
}
