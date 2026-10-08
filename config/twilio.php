<?php

return [
    'enabled' => (bool) env('TWILIO_ENABLED', false),
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    // E.164 number Twilio gave you for WhatsApp, e.g. +15553639030 (no "whatsapp:" prefix here — the gateway adds it).
    'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
];
