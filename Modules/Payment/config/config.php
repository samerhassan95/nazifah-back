<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This option defines the default payment gateway that will be used
    | when no specific gateway is specified.
    |
    */
    'default' => env('PAYMENT_GATEWAY', 'amazon_pay'),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Here you can configure all payment gateways available in your application.
    | Each gateway can have its own configuration.
    |
    */
    'gateways' => [
        'amazon_pay' => [
            'enabled' => filter_var(env('AMAZON_PAY_ENABLED') ?: env('AMAZON_PAYMENT_SERVICES_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN) ?: true,
            // test_mode + credentials resolved in config/payment.php (do not override here)
            'language' => env('AMAZON_PAY_LANGUAGE') ?: env('AMAZON_PAYMENT_LANGUAGE', 'ar'),
            'currency' => env('AMAZON_PAY_CURRENCY') ?: env('AMAZON_PAYMENT_CURRENCY', 'SAR'),
            // Must match config/payment.php (AUTHORIZATION). This module config is
            // merged on top of the root config, so a different default here would
            // silently override it when AMAZON_PAY_COMMAND is unset.
            'command' => env('AMAZON_PAY_COMMAND') ?: env('AMAZON_PAYMENT_COMMAND', 'AUTHORIZATION'),
        ],

        // Future gateways can be added here
        // 'stripe' => [
        //     'enabled' => env('STRIPE_ENABLED', false),
        //     'test_mode' => env('STRIPE_TEST_MODE', true),
        //     'public_key' => env('STRIPE_PUBLIC_KEY'),
        //     'secret_key' => env('STRIPE_SECRET_KEY'),
        // ],

        // 'paypal' => [
        //     'enabled' => env('PAYPAL_ENABLED', false),
        //     'test_mode' => env('PAYPAL_TEST_MODE', true),
        //     'client_id' => env('PAYPAL_CLIENT_ID'),
        //     'secret' => env('PAYPAL_SECRET'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Default currency for payments
    |
    */
    'currency' => env('PAYMENT_CURRENCY', 'SAR'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Configure webhook settings for payment notifications
    |
    */
    'webhooks' => [
        'enabled' => env('PAYMENT_WEBHOOKS_ENABLED', true),
        'verify_signature' => env('PAYMENT_WEBHOOKS_VERIFY_SIGNATURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Customer Email
    |--------------------------------------------------------------------------
    |
    | This email will be used as a default if no customer email is provided.
    |
    */
    'default_email' => env('PAYMENT_DEFAULT_EMAIL', 'no-reply@nathefah.com'),
];
