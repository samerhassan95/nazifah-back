<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Nathefah Checkout') }}</title>

    <!-- Moyasar Styles -->
    <link rel="stylesheet" href="https://cdn.moyasar.com/mpf/1.14.0/moyasar.css" />

    <style>
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        .checkout-container {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            padding-bottom: 2rem;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
        }
        .topbar .back-arrow {
            width: 20px;
            height: 20px;
            color: #111827;
            flex: 0 0 auto;
        }
        .topbar h1 {
            font-size: 1.125rem;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        .topbar-spacer {
            background: #f3f4f6;
            height: 3rem;
        }
        .amount-block {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 1.5rem 1.5rem 0.5rem;
        }
        .amount-block .meta {
            display: flex;
            flex-direction: column;
        }
        .amount-block .amount {
            font-size: 1.75rem;
            font-weight: 800;
            color: #111827;
        }
        .amount-block .datetime,
        .amount-block .reference {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 0.35rem;
            word-break: break-all;
        }
        .amount-block img.brand-logo {
            max-height: 42px;
            border-radius: 8px;
        }
        .description-box {
            margin: 1rem 1.5rem 1.5rem;
            background: #f3f4f6;
            border-radius: 8px;
            padding: 0.9rem 1rem;
            font-size: 0.9rem;
            color: #374151;
        }
        .mysr-form-wrap {
            padding: 0 1.5rem;
        }
        .secure-badge {
            text-align: center;
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }
        .secure-badge svg {
            width: 14px;
            height: 14px;
        }
    </style>
</head>
<body>
    <div class="checkout-container">
        <div class="topbar">
            <h1>{{ __('payment.complete_your_payment') }}</h1>
            <svg class="back-arrow" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M{{ app()->getLocale() === 'ar' ? '14 5l7 7-7 7M21 12H3' : '10 19l-7-7 7-7M3 12h18' }}" />
            </svg>
        </div>
        <div class="topbar-spacer"></div>

        <div class="amount-block">
            <div class="meta">
                <span class="amount">{{ number_format(($moyasarConfig['amount'] ?? 0) / 100, 2) }} {{ $moyasarConfig['currency'] ?? 'SAR' }}</span>
                <span class="datetime">{{ now()->format('Y-m-d h:i A') }}</span>
                <span class="reference">{{ $transaction->transaction_id }}</span>
            </div>
            @if(!empty($moyasarConfig['logo_url']))
                <img class="brand-logo" src="{{ $moyasarConfig['logo_url'] }}" alt="Logo">
            @else
                <img class="brand-logo" src="https://back.nathefah.com/logo.jpeg" alt="Nathefah Logo" onerror="this.style.display='none'">
            @endif
        </div>

        <div class="description-box">{{ $moyasarConfig['description'] ?? 'Nathefah Order' }}</div>

        <!-- The Moyasar Form Container -->
        <div class="mysr-form-wrap">
            <div class="mysr-form"></div>
        </div>

        <div class="secure-badge">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
            {{ __('Secured by Moyasar') }}
        </div>
    </div>

    <!-- Moyasar Scripts (no polyfill.io — that host hangs and times out WebViews) -->
    <script src="https://cdn.moyasar.com/mpf/1.14.0/moyasar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var config = @json($moyasarConfig);
            var ua = navigator.userAgent || '';
            var isIos = /iP(hone|ad|od)/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            // Flutter WebView often hides the model ("Android 13; K") so SM-/SAMSUNG
            // is missing. If the Service ID is present, let Moyasar show/hide the button.

            var methods = (config.methods || ['creditcard', 'stcpay']).filter(function (method) {
                if (method === 'applepay') return isIos;
                return method !== 'mada' && method !== 'samsungpay';
            });
            if (methods.indexOf('creditcard') === -1 && methods.indexOf('stcpay') === -1) {
                methods.push('creditcard');
            }

            var init = {
                element: '.mysr-form',
                amount: config.amount,
                currency: config.currency,
                description: config.description,
                publishable_api_key: config.publishable_api_key,
                callback_url: config.callback_url,
                methods: methods,
                metadata: config.metadata || {},
                language: config.language || 'ar',
                supported_networks: config.supported_networks || ['mada', 'visa', 'mastercard']
            };

            if (config.invoice_id) {
                init.invoice_id = config.invoice_id;
            }
            if (config.manual) {
                init.manual = config.manual;
            }
            if (config.apple_pay && methods.indexOf('applepay') !== -1) {
                init.apple_pay = config.apple_pay;
            }

            Moyasar.init(init);
        });
    </script>
</body>
</html>
