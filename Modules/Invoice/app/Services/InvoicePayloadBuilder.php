<?php

namespace Modules\Invoice\Services;

use Modules\Invoice\Models\Invoice;
use Modules\Order\Models\Order;

class InvoicePayloadBuilder
{
    public function buildForOrder(Order $order, Invoice $invoice): array
    {
        $order->loadMissing(['client', 'branch.vendor', 'items.piece', 'items.service']);

        $lineItems = $order->items->map(function ($item) {
            return [
                'piece_id' => $item->piece_id,
                'piece_name' => $item->piece?->name,
                'service_id' => $item->service_id,
                'service_name' => $item->service?->service_name,
                'quantity' => (int) ($item->quantity ?? 1),
                'unit_price' => round((float) ($item->unit_price ?? 0), 2),
                'total_price' => round((float) ($item->total_price ?? 0), 2),
            ];
        })->values()->all();

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
            'tax_amount' => round((float) $invoice->tax_amount, 2),
            'delivery_fee' => round((float) $invoice->delivery_fee, 2),
            'total_amount' => round((float) $invoice->total_amount, 2),
            'line_items' => $lineItems,
        ];
    }
}
