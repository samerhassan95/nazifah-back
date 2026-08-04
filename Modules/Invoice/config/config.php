<?php

return [
    'auto_issue' => env('INVOICE_AUTO_ISSUE', true),
    'issue_cod_invoices' => env('INVOICE_ISSUE_COD', false),
    'public_link_ttl_days' => (int) env('INVOICE_PUBLIC_LINK_TTL_DAYS', 30),
    'currency' => env('INVOICE_CURRENCY', 'SAR'),
    'company' => [
        'name_ar' => env('INVOICE_COMPANY_NAME_AR', 'نظيفة'),
        'name_en' => env('INVOICE_COMPANY_NAME_EN', 'Nathefah'),
        'vat_number' => env('INVOICE_COMPANY_VAT_NUMBER'),
        'registration_number' => env('INVOICE_COMPANY_REGISTRATION_NUMBER'),
        'address' => env('INVOICE_COMPANY_ADDRESS'),
    ],
    'zatca' => [
        'enabled' => filter_var(env('INVOICE_ZATCA_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'driver' => env('INVOICE_ZATCA_DRIVER', 'mock'),
        'environment' => env('INVOICE_ZATCA_ENVIRONMENT', 'sandbox'),
        'base_url' => env('INVOICE_ZATCA_BASE_URL'),
        'submit_path' => env('INVOICE_ZATCA_SUBMIT_PATH', '/invoices'),
        'api_key' => env('INVOICE_ZATCA_API_KEY'),
        'timeout' => (int) env('INVOICE_ZATCA_TIMEOUT', 20),
    ],
    'whatsapp' => [
        'enabled' => filter_var(env('INVOICE_WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'driver' => env('INVOICE_WHATSAPP_DRIVER', 'mock'),
        'base_url' => env('INVOICE_WHATSAPP_BASE_URL'),
        'send_path' => env('INVOICE_WHATSAPP_SEND_PATH', '/messages'),
        'api_key' => env('INVOICE_WHATSAPP_API_KEY'),
        'template_name' => env('INVOICE_WHATSAPP_TEMPLATE', 'invoice_link'),
        'sender' => env('INVOICE_WHATSAPP_SENDER'),
        'timeout' => (int) env('INVOICE_WHATSAPP_TIMEOUT', 20),
    ],
];
