<?php

namespace Modules\Invoice\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Modules\Invoice\Contracts\InvoiceComplianceGatewayInterface;
use Modules\Invoice\Contracts\WhatsappInvoiceGatewayInterface;
use Modules\Invoice\Jobs\SendInvoiceWhatsappJob;
use Modules\Invoice\Jobs\SubmitInvoiceToZatcaJob;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceAttempt;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderPaymentService;
use Modules\Payment\Models\PaymentTransaction;

class InvoiceService
{
    public function __construct(
        private InvoicePayloadBuilder $payloadBuilder,
        private InvoiceComplianceGatewayInterface $zatcaGateway,
        private WhatsappInvoiceGatewayInterface $whatsappGateway,
        private OrderPaymentService $orderPaymentService,
        private InvoiceSettingsService $invoiceSettings,
    ) {}

    public function createOrFetchForOrder(Order $order): Invoice
    {
        $invoice = Invoice::where('order_id', $order->id)->first();
        if ($invoice) {
            return $invoice;
        }

        $order->loadMissing(['client', 'branch.vendor', 'items.piece', 'items.service']);

        $invoice = Invoice::firstOrNew(['order_id' => $order->id]);
        $invoice->fill([
            'client_id' => $order->client_id,
            'vendor_id' => $order->resolveVendorId(),
            'branch_id' => $order->branch_id,
            'invoice_number' => $invoice->invoice_number ?: $this->generateInvoiceNumber($order),
            'invoice_type' => 'simplified_tax_invoice',
            'currency' => (string) $this->invoiceSettings->get('invoice_currency', 'SAR'),
            'status' => $invoice->status ?: Invoice::STATUS_DRAFT,
            'subtotal_amount' => round((float) $order->total_amount, 2),
            'discount_amount' => round((float) $order->discount_amount, 2),
            'tax_amount' => round((float) $order->tax_amount, 2),
            'delivery_fee' => round((float) $order->delivery_fee, 2),
            'total_amount' => round((float) $order->final_amount, 2),
            'customer_name' => $order->client?->full_name,
            'customer_phone' => $order->client?->phone,
            'customer_email' => $order->client?->email,
            'seller_name' => $this->resolveSellerName($order),
            'seller_vat_number' => $order->branch?->vendor?->vat_number ?: $this->invoiceSettings->get('invoice_company_vat_number'),
            'seller_registration_number' => $order->branch?->vendor?->official_number ?: $this->invoiceSettings->get('invoice_company_registration_number'),
            'seller_address' => $order->branch?->national_address ?? $this->invoiceSettings->get('invoice_company_address'),
            'issued_at' => $invoice->issued_at ?: now(),
            'last_error' => null,
        ]);
        $invoice->save();

        $payload = $this->payloadBuilder->buildForOrder($order, $invoice);
        $invoice->forceFill(['invoice_payload' => $payload])->save();

        return $invoice;
    }

    public function issueForOrder(Order $order, ?PaymentTransaction $transaction = null, string $reason = 'payment_completed'): ?Invoice
    {
        if (! $this->invoiceSettings->get('invoice_auto_issue', true)) {
            return null;
        }

        $order->loadMissing(['client', 'branch.vendor', 'items.piece', 'items.service']);

        if ($order->isCashOnDelivery() && ! $this->invoiceSettings->get('invoice_issue_cod', false)) {
            return null;
        }

        if ($transaction && ! $this->transactionRepresentsCompletedPayment($transaction)) {
            return null;
        }

        if (! $this->orderPaymentService->isFullyPaid($order) && ! $order->isPaid()) {
            return null;
        }

        $invoice = Invoice::firstOrNew(['order_id' => $order->id]);
        $invoice->fill([
            'payment_transaction_id' => $transaction?->id ?? $invoice->payment_transaction_id,
            'client_id' => $order->client_id,
            'vendor_id' => $order->resolveVendorId(),
            'branch_id' => $order->branch_id,
            'invoice_number' => $invoice->invoice_number ?: $this->generateInvoiceNumber($order),
            'invoice_type' => 'simplified_tax_invoice',
            'currency' => (string) $this->invoiceSettings->get('invoice_currency', 'SAR'),
            'status' => $invoice->status ?: Invoice::STATUS_DRAFT,
            'subtotal_amount' => round((float) $order->total_amount, 2),
            'discount_amount' => round((float) $order->discount_amount, 2),
            'tax_amount' => round((float) $order->tax_amount, 2),
            'delivery_fee' => round((float) $order->delivery_fee, 2),
            'total_amount' => round((float) $order->final_amount, 2),
            'customer_name' => $order->client?->full_name,
            'customer_phone' => $order->client?->phone,
            'customer_email' => $order->client?->email,
            'seller_name' => $this->resolveSellerName($order),
            'seller_vat_number' => $order->branch?->vendor?->vat_number ?: $this->invoiceSettings->get('invoice_company_vat_number'),
            'seller_registration_number' => $order->branch?->vendor?->official_number ?: $this->invoiceSettings->get('invoice_company_registration_number'),
            'seller_address' => $order->branch?->national_address ?? $this->invoiceSettings->get('invoice_company_address'),
            'issued_at' => $invoice->issued_at ?: ($transaction?->paid_at ?? now()),
            'last_error' => null,
        ]);
        $invoice->save();

        $payload = $this->payloadBuilder->buildForOrder($order, $invoice);
        $invoice->forceFill(['invoice_payload' => $payload])->save();

        if (in_array($invoice->status, [Invoice::STATUS_REPORTED, Invoice::STATUS_SENT_WHATSAPP], true)) {
            return $invoice;
        }

        Log::info('Invoice queued for compliance submission', [
            'invoice_id' => $invoice->id,
            'order_id' => $order->id,
            'reason' => $reason,
        ]);

        if ($this->invoiceSettings->get('invoice_zatca_enabled', false)) {
            $invoice->update(['status' => Invoice::STATUS_SUBMITTED, 'zatca_status' => 'queued']);
            SubmitInvoiceToZatcaJob::dispatch($invoice->id);
        } else {
            $invoice->update(['zatca_status' => 'disabled']);
        }

        return $invoice;
    }

    public function submitToZatca(Invoice $invoice): Invoice
    {
        $invoice->loadMissing('order.items.piece', 'order.items.service', 'client', 'vendor', 'branch');

        $payload = $invoice->invoice_payload ?: $this->payloadBuilder->buildForOrder($invoice->order, $invoice);
        $result = $this->zatcaGateway->submitSimplifiedInvoice($invoice, $payload);

        $this->recordAttempt($invoice, 'zatca', (string) $this->invoiceSettings->get('invoice_zatca_driver', 'mock'), $result->status, $result->requestPayload, $result->responsePayload, $result->errorMessage);

        if (! $result->success) {
            $invoice->update([
                'status' => Invoice::STATUS_FAILED,
                'zatca_status' => $result->status,
                'last_error' => $result->errorMessage,
                'provider_payload' => $result->requestPayload,
                'provider_response' => $result->responsePayload,
            ]);

            return $invoice->fresh();
        }

        $invoice->update([
            'status' => Invoice::STATUS_REPORTED,
            'zatca_status' => $result->status,
            'zatca_reference' => $result->reference,
            'zatca_uuid' => $result->uuid,
            'zatca_invoice_hash' => $result->invoiceHash,
            'zatca_qr_code' => $result->qrCode,
            'reported_at' => now(),
            'provider_payload' => $result->requestPayload,
            'provider_response' => $result->responsePayload,
            'last_error' => null,
        ]);

        if ($this->invoiceSettings->get('invoice_whatsapp_enabled', false)) {
            SendInvoiceWhatsappJob::dispatch($invoice->id);
        } else {
            $invoice->update(['whatsapp_status' => 'disabled']);
        }

        return $invoice->fresh();
    }

    public function sendWhatsapp(Invoice $invoice): Invoice
    {
        $invoice->loadMissing('order.client');
        $shareUrl = $this->shareUrl($invoice);

        $payload = [
            'to' => $invoice->customer_phone,
            'template_name' => $this->invoiceSettings->get('invoice_whatsapp_template'),
            'sender' => $this->invoiceSettings->get('invoice_whatsapp_sender'),
            'variables' => [
                'customer_name' => $invoice->customer_name,
                'order_number' => $invoice->order?->order_number,
                'invoice_number' => $invoice->invoice_number,
                'amount' => round((float) $invoice->total_amount, 2),
                'invoice_url' => $shareUrl,
            ],
        ];

        $result = $this->whatsappGateway->sendInvoiceLink($invoice, $payload);

        $this->recordAttempt($invoice, 'whatsapp', (string) $this->invoiceSettings->get('invoice_whatsapp_driver', 'mock'), $result->status, $result->requestPayload, $result->responsePayload, $result->errorMessage);

        if (! $result->success) {
            $invoice->update([
                'whatsapp_status' => $result->status,
                'last_error' => $result->errorMessage,
            ]);

            return $invoice->fresh();
        }

        $invoice->update([
            'status' => Invoice::STATUS_SENT_WHATSAPP,
            'whatsapp_status' => $result->status,
            'whatsapp_sent_at' => now(),
            'last_error' => null,
        ]);

        return $invoice->fresh();
    }

    public function shareUrl(Invoice $invoice): string
    {
        return URL::temporarySignedRoute(
            'invoice.share',
            now()->addDays((int) $this->invoiceSettings->get('invoice_public_link_ttl_days', 30)),
            ['invoice' => $invoice->id]
        );
    }

    private function generateInvoiceNumber(Order $order): string
    {
        return 'INV-'.$order->id.'-'.now()->format('YmdHis');
    }

    private function transactionRepresentsCompletedPayment(PaymentTransaction $transaction): bool
    {
        if ($transaction->gateway === 'wallet') {
            return $transaction->status === 'completed';
        }

        return $transaction->status === 'completed';
    }

    private function resolveSellerName(Order $order): ?string
    {
        $fallbackName = app()->getLocale() === 'ar'
            ? $this->invoiceSettings->get('invoice_company_name_ar')
            : $this->invoiceSettings->get('invoice_company_name_en');

        return $order->branch?->vendor?->getTranslatedName()
            ?: $order->branch?->getTranslation('name', app()->getLocale())
            ?: $fallbackName
            ?: $this->invoiceSettings->get('invoice_company_name_en');
    }

    private function recordAttempt(
        Invoice $invoice,
        string $channel,
        string $provider,
        string $status,
        ?array $requestPayload,
        ?array $responsePayload,
        ?string $errorMessage,
    ): void {
        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'channel' => $channel,
            'provider' => $provider,
            'status' => $status,
            'error_message' => $errorMessage,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'attempted_at' => now(),
        ]);
    }
}
