<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Nathefah Checkout') }}</title>

    <!-- Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">

    <!-- Moyasar Styles -->
    <link rel="stylesheet" href="https://cdn.moyasar.com/mpf/1.14.0/moyasar.css" />
    
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .checkout-container {
            width: 100%;
            max-width: 500px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 2rem;
            margin: 1rem;
        }
        .checkout-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .checkout-header img {
            max-height: 60px;
            margin-bottom: 1rem;
        }
        .checkout-header h1 {
            font-size: 1.5rem;
            color: #1f2937;
            margin: 0;
            font-weight: 700;
        }
        .checkout-header p {
            color: #6b7280;
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }
        .order-details {
            background: #f9fafb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            border: 1px solid #e5e7eb;
        }
        .order-details div {
            display: flex;
            flex-direction: column;
        }
        .order-details span.label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .order-details span.value {
            font-size: 1.125rem;
            font-weight: 700;
            color: #111827;
        }
        .secure-badge {
            text-align: center;
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .secure-badge svg {
            width: 16px;
            height: 16px;
        }
    </style>
</head>
<body>
    <div class="checkout-container">
        <div class="checkout-header">
            @if(!empty($moyasarConfig['logo_url']))
                <img src="{{ $moyasarConfig['logo_url'] }}" alt="Logo">
            @else
                <img src="https://back.nathefah.com/logo.jpeg" alt="Nathefah Logo" onerror="this.style.display='none'">
            @endif
            <h1>{{ __('Complete your payment') }}</h1>
            <p>{{ $moyasarConfig['description'] ?? 'Nathefah Order' }}</p>
        </div>

        <div class="order-details">
            <div>
                <span class="label">{{ __('Amount') }}</span>
                <span class="value">{{ number_format(($moyasarConfig['amount'] ?? 0) / 100, 2) }} {{ $moyasarConfig['currency'] ?? 'SAR' }}</span>
            </div>
            <div style="text-align: {{ app()->getLocale() === 'ar' ? 'left' : 'right' }};">
                <span class="label">{{ __('Reference') }}</span>
                <span class="value" style="font-size: 0.875rem;">{{ str_replace('ORD-', '', $transaction->transaction_id) }}</span>
            </div>
        </div>

        <!-- The Moyasar Form Container -->
        <div class="mysr-form"></div>

        <div class="secure-badge">
            <svg xmlns="http://www.w3.org/Dom/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
            {{ __('Secured by Moyasar') }}
        </div>
    </div>

    <!-- Moyasar Scripts -->
    <script src="https://polyfill.io/v3/polyfill.min.js?features=fetch"></script>
    <script src="https://cdn.moyasar.com/mpf/1.14.0/moyasar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var config = @json($moyasarConfig);
            
            // Initialize Moyasar Payment Form
            Moyasar.init({
                element: '.mysr-form',
                amount: config.amount,
                currency: config.currency,
                description: config.description,
                publishable_api_key: config.publishable_api_key,
                callback_url: config.callback_url,
                methods: config.methods,
                metadata: config.metadata,
                apple_pay: {
                    country: 'SA',
                    label: 'Nathefah',
                    validate_merchant_url: 'https://api.moyasar.com/v1/applepay/initiate'
                }
            });
        });
    </script>
</body>
</html>
