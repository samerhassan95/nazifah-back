<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; color: #111827; }
        .row { margin-bottom: 12px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        h1, h2, h3, p { margin: 0 0 12px; }
    </style>
</head>
<body>
    <h1>فاتورة ضريبية مبسطة</h1>
    <p class="muted">Invoice #{{ $invoice->invoice_number }}</p>

    <div class="row"><strong>الطلب:</strong> {{ $invoice->order?->order_number }}</div>
    <div class="row"><strong>العميل:</strong> {{ $invoice->customer_name }}</div>
    <div class="row"><strong>الجوال:</strong> {{ $invoice->customer_phone }}</div>
    <div class="row"><strong>البائع:</strong> {{ $invoice->seller_name }}</div>
    <div class="row"><strong>الرقم الضريبي:</strong> {{ $invoice->seller_vat_number }}</div>
    <div class="row"><strong>تاريخ الإصدار:</strong> {{ optional($invoice->issued_at)->toDateTimeString() }}</div>

    <table>
        <thead>
            <tr>
                <th>القطعة</th>
                <th>الخدمة</th>
                <th>الكمية</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($payload['line_items'] ?? []) as $line)
                <tr>
                    <td>{{ is_array($line['piece_name'] ?? null) ? ($line['piece_name']['ar'] ?? $line['piece_name']['en'] ?? '') : ($line['piece_name'] ?? '') }}</td>
                    <td>{{ is_array($line['service_name'] ?? null) ? ($line['service_name']['ar'] ?? $line['service_name']['en'] ?? '') : ($line['service_name'] ?? '') }}</td>
                    <td>{{ $line['quantity'] ?? 1 }}</td>
                    <td>{{ number_format((float) ($line['total_price'] ?? 0), 2) }} {{ $invoice->currency }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>الملخص</h3>
    <div class="row"><strong>Subtotal:</strong> {{ number_format((float) $invoice->subtotal_amount, 2) }} {{ $invoice->currency }}</div>
    <div class="row"><strong>Discount:</strong> {{ number_format((float) $invoice->discount_amount, 2) }} {{ $invoice->currency }}</div>
    <div class="row"><strong>Tax:</strong> {{ number_format((float) $invoice->tax_amount, 2) }} {{ $invoice->currency }}</div>
    <div class="row"><strong>Delivery:</strong> {{ number_format((float) $invoice->delivery_fee, 2) }} {{ $invoice->currency }}</div>
    <div class="row"><strong>Total:</strong> {{ number_format((float) $invoice->total_amount, 2) }} {{ $invoice->currency }}</div>
</body>
</html>
