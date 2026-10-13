<?php

return [
    'enabled' => (bool) env('TWILIO_ENABLED', false),
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    // E.164 number Twilio gave you for WhatsApp, e.g. +15553639030 (no "whatsapp:" prefix here — the gateway adds it).
    'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    // Content SID (starts "HX") of the approved WhatsApp Authentication template used for
    // OTP codes — a separate template/category from the invoice-link one, approved by
    // Meta specifically for one-time-passcode delivery. Takes exactly one ContentVariable:
    // the code itself.
    'otp_content_sid' => env('TWILIO_OTP_CONTENT_SID'),
];
