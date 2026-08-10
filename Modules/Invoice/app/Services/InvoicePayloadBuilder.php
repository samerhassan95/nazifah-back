<?php

namespace Modules\Invoice\Services;

use Modules\Invoice\Models\Invoice;
use Modules\Order\Models\Order;

class InvoicePayloadBuilder
{
    /**
     * VAT rate (15% for Saudi Arabia).
     */
    private const VAT_RATE = 0.15;

    public function buildForOrder(Order $order, Invoice $invoice): array
    {
        $order->loadMissing(['client', 'branch.vendor', 'items.piece', 'items.service']);

        $lineItems = $order->items->map(function ($item) {
            $totalPrice = round((float) ($item->total_price ?? 0), 2);
            $priceExclVat = round($totalPrice / (1 + self::VAT_RATE), 2);
            $vatAmount = round($totalPrice - $priceExclVat, 2);

            return [
                'piece_id' => $item->piece_id,
                'piece_name' => $item->piece?->name,
                'service_id' => $item->service_id,
                'service_name' => $item->service?->service_name,
                'quantity' => (int) ($item->quantity ?? 1),
                'unit_price' => round((float) ($item->unit_price ?? 0), 2),
                'total_price' => $totalPrice,
                'price_excl_vat' => $priceExclVat,
                'vat_amount' => $vatAmount,
                'vat_rate' => self::VAT_RATE * 100,
            ];
        })->values()->all();

        $totalAmount = round((float) $invoice->total_amount, 2);
        $taxAmount = round((float) $invoice->tax_amount, 2);

        $zatcaQr = $this->generateZatcaTlvQrCode(
            sellerName: (string) $invoice->seller_name,
            vatNumber: (string) $invoice->seller_vat_number,
            timestamp: optional($invoice->issued_at)->toIso8601String() ?? now()->toIso8601String(),
            totalWithVat: $totalAmount,
            vatTotal: $taxAmount,
        );

        return [
            'invoice_number' => $invoice->invoice_number,
            'invoice_type' => $invoice->invoice_type,
            'issue_date' => optional($invoice->issued_at)->toIso8601String(),
            'currency' => $invoice->currency,
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
            ],
            'seller' => [
                'name' => $invoice->seller_name,
                'vat_number' => $invoice->seller_vat_number,
                'registration_number' => $invoice->seller_registration_number,
                'address' => $invoice->seller_address,
            ],
            'customer' => [
                'name' => $invoice->customer_name,
                'phone' => $invoice->customer_phone,
                'email' => $invoice->customer_email,
            ],
            'subtotal_amount' => round((float) $invoice->subtotal_amount, 2),
            'discount_amount' => round((float) $invoice->discount_amount, 2),
            'tax_amount' => $taxAmount,
            'delivery_fee' => round((float) $invoice->delivery_fee, 2),
            'total_amount' => $totalAmount,
            'vat_rate' => self::VAT_RATE * 100,
            'line_items' => $lineItems,
            'zatca_qr_base64' => $zatcaQr,
        ];
    }

    /**
     * Generate a ZATCA Phase-1 compliant QR Code as Base64-encoded TLV (Tag-Length-Value).
     *
     * Tags:
     *  1 – Seller's Name (UTF-8)
     *  2 – VAT Registration Number (UTF-8)
     *  3 – Invoice Date/Time (ISO 8601 UTC, UTF-8)
     *  4 – Invoice Total (with VAT, UTF-8)
     *  5 – VAT Total (UTF-8)
     */
    private function generateZatcaTlvQrCode(
        string $sellerName,
        string $vatNumber,
        string $timestamp,
        float $totalWithVat,
        float $vatTotal,
    ): string {
        $tlv = '';
        $tlv .= $this->tlvTag(1, $sellerName);
        $tlv .= $this->tlvTag(2, $vatNumber);
        $tlv .= $this->tlvTag(3, $timestamp);
        $tlv .= $this->tlvTag(4, number_format($totalWithVat, 2, '.', ''));
        $tlv .= $this->tlvTag(5, number_format($vatTotal, 2, '.', ''));

        return base64_encode($tlv);
    }

    /**
     * Encode a single TLV tag: tag byte + length byte + value bytes.
     */
    private function tlvTag(int $tag, string $value): string
    {
        $valueBytes = $value;
        $length = strlen($valueBytes);

        return chr($tag) . chr($length) . $valueBytes;
    }
}
