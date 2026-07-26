<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deewan SMS API Configuration
    |--------------------------------------------------------------------------
    | Supports two authentication methods:
    |
    | 1. LEGACY (developer.deewan.sa):
    |    - Uses direct JWT token
    |    - Set: DEEWAN_SMS_TOKEN
    |    - Base URL: https://developer.deewan.sa
    |
    | 2. NEW (api.deewan.sa:8445):
    |    - Uses username + apiKey with Sign-in flow
    |    - Set: DEEWAN_SMS_USERNAME and DEEWAN_SMS_API_KEY
    |    - Base URL: https://api.deewan.sa:8445
    |
    | The service auto-detects which method to use.
    */

    'sms' => [
        'enabled' => env('DEEWAN_SMS_ENABLED', true),

        // Legacy authentication (developer.deewan.sa)
        'legacy_token' => env('DEEWAN_SMS_TOKEN'),

        // New authentication (api.deewan.sa:8445)
        'username' => env('DEEWAN_SMS_USERNAME'),
        'api_key' => env('DEEWAN_SMS_API_KEY'),

        // Common settings
        'base_url' => env('DEEWAN_SMS_BASE_URL', 'https://api.deewan.sa:8445'),
        'sender' => env('DEEWAN_SMS_SENDER', 'Nathefah'),
        'dry_run' => env('DEEWAN_SMS_DRY_RUN', false),
    ],

    /*
    | OTP channel: 'sms' = Deewan SMS, 'whatsapp' = WhatsApp Baileys
    */
    'otp_channel' => env('OTP_CHANNEL', 'sms'),

];
