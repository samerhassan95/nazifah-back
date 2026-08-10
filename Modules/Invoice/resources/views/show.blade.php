<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة ضريبية مبسطة | {{ $invoice->invoice_number }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            line-height: 1.6;
            padding: 24px;
        }

        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        /* Header */
        .invoice-header {
            background: linear-gradient(135deg, #0d7377, #14919b);
            color: #fff;
            padding: 28px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .invoice-header h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .invoice-header .subtitle {
            font-size: 13px;
            opacity: 0.85;
        }
        .invoice-header .invoice-number {
            text-align: left;
            direction: ltr;
        }
        .invoice-header .invoice-number .num {
            font-size: 16px;
            font-weight: 700;
            background: rgba(255,255,255,0.2);
            padding: 4px 14px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 4px;
        }
        .invoice-header .invoice-number .date {
            font-size: 12px;
            opacity: 0.85;
        }

        /* Body */
        .invoice-body { padding: 28px 32px; }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }
        .info-card {
            background: #f8fafb;
            border: 1px solid #e8ecef;
            border-radius: 10px;
            padding: 18px 20px;
        }
        .info-card h3 {
            font-size: 13px;
            color: #0d7377;
            font-weight: 700;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #0d737720;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .info-row .label {
            color: #6b7280;
            font-weight: 600;
        }
        .info-row .value {
            font-weight: 600;
            color: #1a1a2e;
            direction: ltr;
        }

        /* Items Table */
        .items-section h3 {
            font-size: 15px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            font-size: 13px;
        }
        thead th {
            background: #0d7377;
            color: #fff;
            padding: 10px 12px;
            text-align: right;
            font-weight: 600;
            font-size: 12px;
        }
        thead th:first-child { border-radius: 0 8px 0 0; }
        thead th:last-child { border-radius: 8px 0 0 0; }
        tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        tbody tr:hover { background: #f8fafb; }
        tbody tr:last-child td { border-bottom: none; }

        /* Totals */
        .totals-section {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 24px;
        }
        .totals-card {
            background: linear-gradient(135deg, #f8fafb, #eef2f5);
            border: 1px solid #e0e5ea;
            border-radius: 10px;
            padding: 18px 24px;
            min-width: 320px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .total-row .label { color: #6b7280; }
        .total-row .value {
            font-weight: 600;
            direction: ltr;
        }
        .total-row.grand {
            border-top: 2px solid #0d7377;
            padding-top: 10px;
            margin-top: 6px;
            font-size: 16px;
        }
        .total-row.grand .label {
            color: #0d7377;
            font-weight: 700;
        }
        .total-row.grand .value {
            color: #0d7377;
            font-weight: 700;
        }

        /* QR Section */
        .qr-section {
            text-align: center;
            padding: 20px;
            border-top: 1px dashed #ddd;
            margin-top: 12px;
        }
        .qr-section p {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 8px;
        }
        .qr-section img {
            width: 160px;
            height: 160px;
        }

        /* Footer */
        .invoice-footer {
            background: #f8fafb;
            padding: 16px 32px;
            text-align: center;
            border-top: 1px solid #e8ecef;
        }
        .invoice-footer p {
            font-size: 11px;
            color: #9ca3af;
        }

        /* Action Bar */
        .action-bar {
            text-align: center;
            padding: 20px;
            background: #fff;
            max-width: 800px;
            margin: 12px auto 0;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }
        .btn-print {
            background: linear-gradient(135deg, #0d7377, #14919b);
            color: #fff;
            border: none;
            padding: 12px 36px;
            border-radius: 8px;
            font-size: 15px;
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .btn-print:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(13,115,119,0.3);
        }

        /* Print Styles */
        @media print {
            body { background: #fff; padding: 0; }
            .invoice-container { box-shadow: none; border-radius: 0; }
            .action-bar { display: none !important; }
            .invoice-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        @media (max-width: 600px) {
            .info-grid { grid-template-columns: 1fr; }
            .invoice-header { flex-direction: column; text-align: center; gap: 12px; }
            .invoice-header .invoice-number { text-align: center; }
            .totals-card { min-width: 100%; }
            .invoice-body { padding: 20px 16px; }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        {{-- Header --}}
        <div class="invoice-header">
            <div>
                <h1>فاتورة ضريبية مبسطة</h1>
                <div class="subtitle">Simplified Tax Invoice</div>
            </div>
            <div class="invoice-number">
                <div class="num">#{{ $invoice->invoice_number }}</div>
                <div class="date">{{ optional($invoice->issued_at)->format('Y-m-d H:i') }}</div>
            </div>
        </div>

        {{-- Body --}}
        <div class="invoice-body">
            {{-- Info Cards --}}
            <div class="info-grid">
                {{-- Seller --}}
                <div class="info-card">
                    <h3>بيانات البائع / Seller</h3>
                    <div class="info-row">
                        <span class="label">الاسم</span>
                        <span class="value">{{ $invoice->seller_name }}</span>
                    </div>
                    <div class="info-row">
                        <span class="label">الرقم الضريبي (TRN)</span>
                        <span class="value">{{ $invoice->seller_vat_number ?? '—' }}</span>
                    </div>
                    @if($invoice->seller_registration_number)
                    <div class="info-row">
                        <span class="label">السجل التجاري</span>
                        <span class="value">{{ $invoice->seller_registration_number }}</span>
                    </div>
                    @endif
                    @if($invoice->seller_address)
                    <div class="info-row">
                        <span class="label">العنوان</span>
                        <span class="value">{{ $invoice->seller_address }}</span>
                    </div>
                    @endif
                </div>

                {{-- Customer & Order --}}
                <div class="info-card">
                    <h3>بيانات العميل والطلب / Customer & Order</h3>
                    <div class="info-row">
                        <span class="label">العميل</span>
                        <span class="value">{{ $invoice->customer_name }}</span>
                    </div>
                    <div class="info-row">
                        <span class="label">الجوال</span>
                        <span class="value">{{ $invoice->customer_phone }}</span>
                    </div>
                    <div class="info-row">
                        <span class="label">رقم الطلب</span>
                        <span class="value">{{ $invoice->order?->order_number }}</span>
                    </div>
                    <div class="info-row">
                        <span class="label">طريقة الدفع</span>
                        <span class="value">{{ $payload['order']['payment_method'] ?? '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- Line Items Table --}}
            <div class="items-section">
                <h3>تفاصيل الخدمات / Items</h3>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>القطعة</th>
                            <th>الخدمة</th>
                            <th>الكمية</th>
                            <th>السعر (شامل)</th>
                            <th>الضريبة ({{ $payload['vat_rate'] ?? 15 }}%)</th>
                            <th>الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (($payload['line_items'] ?? []) as $i => $line)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ is_array($line['piece_name'] ?? null) ? ($line['piece_name']['ar'] ?? $line['piece_name']['en'] ?? '') : ($line['piece_name'] ?? '') }}</td>
                                <td>{{ is_array($line['service_name'] ?? null) ? ($line['service_name']['ar'] ?? $line['service_name']['en'] ?? '') : ($line['service_name'] ?? '') }}</td>
                                <td>{{ $line['quantity'] ?? 1 }}</td>
                                <td>{{ number_format((float) ($line['price_excl_vat'] ?? $line['unit_price'] ?? 0), 2) }}</td>
                                <td>{{ number_format((float) ($line['vat_amount'] ?? 0), 2) }}</td>
                                <td>{{ number_format((float) ($line['total_price'] ?? 0), 2) }} {{ $invoice->currency }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Totals --}}
            <div class="totals-section">
                <div class="totals-card">
                    <div class="total-row">
                        <span class="label">المجموع الفرعي (Subtotal)</span>
                        <span class="value">{{ number_format((float) $invoice->subtotal_amount, 2) }} {{ $invoice->currency }}</span>
                    </div>
                    @if((float) $invoice->discount_amount > 0)
                    <div class="total-row">
                        <span class="label">الخصم (Discount)</span>
                        <span class="value" style="color: #e74c3c;">- {{ number_format((float) $invoice->discount_amount, 2) }} {{ $invoice->currency }}</span>
                    </div>
                    @endif
                    @if((float) $invoice->delivery_fee > 0)
                    <div class="total-row">
                        <span class="label">رسوم التوصيل (Delivery)</span>
                        <span class="value">{{ number_format((float) $invoice->delivery_fee, 2) }} {{ $invoice->currency }}</span>
                    </div>
                    @endif
                    <div class="total-row">
                        <span class="label">ضريبة القيمة المضافة ({{ $payload['vat_rate'] ?? 15 }}% VAT)</span>
                        <span class="value">{{ number_format((float) $invoice->tax_amount, 2) }} {{ $invoice->currency }}</span>
                    </div>
                    <div class="total-row grand">
                        <span class="label">الإجمالي (Total)</span>
                        <span class="value">{{ number_format((float) $invoice->total_amount, 2) }} {{ $invoice->currency }}</span>
                    </div>
                </div>
            </div>

            {{-- ZATCA QR Code --}}
            @if(!empty($payload['zatca_qr_base64']))
            <div class="qr-section">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data={{ urlencode($payload['zatca_qr_base64']) }}" alt="ZATCA QR Code">
                <p>رمز الاستجابة السريعة متوافق مع هيئة الزكاة والضريبة والجمارك (ZATCA Phase 1 TLV)</p>
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="invoice-footer">
            <p>هذه فاتورة ضريبية مبسطة صادرة وفقاً لأنظمة هيئة الزكاة والضريبة والجمارك بالمملكة العربية السعودية</p>
            <p style="margin-top: 4px;">This is a Simplified Tax Invoice issued in accordance with ZATCA regulations of the Kingdom of Saudi Arabia</p>
        </div>
    </div>

    {{-- Print / Download Button --}}
    <div class="action-bar">
        <button class="btn-print" onclick="window.print()">🖨️ طباعة / تحميل PDF</button>
    </div>
</body>
</html>
