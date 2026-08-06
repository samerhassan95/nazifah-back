<?php

namespace Modules\Invoice\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Modules\Admin\Models\AdminSetting;

class InvoiceSettingsService
{
    private const GROUP_INVOICE = 'invoice';

    private const GROUP_ZATCA = 'zatca';

    private const GROUP_WHATSAPP = 'whatsapp';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            'invoice_company_name_ar' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'Company Name (AR)',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.company.name_ar', 'نظيفة'),
            ],
            'invoice_company_name_en' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'Company Name (EN)',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.company.name_en', 'Nathefah'),
            ],
            'invoice_company_vat_number' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'VAT Number',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.company.vat_number'),
            ],
            'invoice_company_registration_number' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'Registration Number',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.company.registration_number'),
            ],
            'invoice_company_address' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'Company Address',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.company.address'),
            ],
            'invoice_auto_issue' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'Auto Issue',
                'type' => AdminSetting::TYPE_BOOLEAN,
                'default' => (bool) config('invoice.auto_issue', true),
            ],
            'invoice_issue_cod' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'Issue COD Invoices',
                'type' => AdminSetting::TYPE_BOOLEAN,
                'default' => (bool) config('invoice.issue_cod_invoices', false),
            ],
            'invoice_public_link_ttl_days' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'Public Link TTL Days',
                'type' => AdminSetting::TYPE_NUMBER,
                'default' => (int) config('invoice.public_link_ttl_days', 30),
            ],
            'invoice_currency' => [
                'group' => self::GROUP_INVOICE,
                'label' => 'Currency',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.currency', 'SAR'),
            ],
            'invoice_zatca_enabled' => [
                'group' => self::GROUP_ZATCA,
                'label' => 'ZATCA Enabled',
                'type' => AdminSetting::TYPE_BOOLEAN,
                'default' => (bool) config('invoice.zatca.enabled', false),
            ],
            'invoice_zatca_driver' => [
                'group' => self::GROUP_ZATCA,
                'label' => 'ZATCA Driver',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.zatca.driver', 'mock'),
            ],
            'invoice_zatca_environment' => [
                'group' => self::GROUP_ZATCA,
                'label' => 'ZATCA Environment',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.zatca.environment', 'sandbox'),
            ],
            'invoice_zatca_base_url' => [
                'group' => self::GROUP_ZATCA,
                'label' => 'ZATCA Base URL',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.zatca.base_url'),
            ],
            'invoice_zatca_submit_path' => [
                'group' => self::GROUP_ZATCA,
                'label' => 'ZATCA Submit Path',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.zatca.submit_path', '/invoices'),
            ],
            'invoice_zatca_api_key' => [
                'group' => self::GROUP_ZATCA,
                'label' => 'ZATCA API Key',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.zatca.api_key'),
                'secret' => true,
            ],
            'invoice_zatca_timeout' => [
                'group' => self::GROUP_ZATCA,
                'label' => 'ZATCA Timeout',
                'type' => AdminSetting::TYPE_NUMBER,
                'default' => (int) config('invoice.zatca.timeout', 20),
            ],
            'invoice_whatsapp_enabled' => [
                'group' => self::GROUP_WHATSAPP,
                'label' => 'WhatsApp Enabled',
                'type' => AdminSetting::TYPE_BOOLEAN,
                'default' => (bool) config('invoice.whatsapp.enabled', false),
            ],
            'invoice_whatsapp_driver' => [
                'group' => self::GROUP_WHATSAPP,
                'label' => 'WhatsApp Driver',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.whatsapp.driver', 'mock'),
            ],
            'invoice_whatsapp_base_url' => [
                'group' => self::GROUP_WHATSAPP,
                'label' => 'WhatsApp Base URL',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.whatsapp.base_url'),
            ],
            'invoice_whatsapp_send_path' => [
                'group' => self::GROUP_WHATSAPP,
                'label' => 'WhatsApp Send Path',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.whatsapp.send_path', '/messages'),
            ],
            'invoice_whatsapp_api_key' => [
                'group' => self::GROUP_WHATSAPP,
                'label' => 'WhatsApp API Key',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.whatsapp.api_key'),
                'secret' => true,
            ],
            'invoice_whatsapp_template' => [
                'group' => self::GROUP_WHATSAPP,
                'label' => 'WhatsApp Template',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.whatsapp.template_name', 'invoice_link'),
            ],
            'invoice_whatsapp_sender' => [
                'group' => self::GROUP_WHATSAPP,
                'label' => 'WhatsApp Sender',
                'type' => AdminSetting::TYPE_TEXT,
                'default' => config('invoice.whatsapp.sender'),
            ],
            'invoice_whatsapp_timeout' => [
                'group' => self::GROUP_WHATSAPP,
                'label' => 'WhatsApp Timeout',
                'type' => AdminSetting::TYPE_NUMBER,
                'default' => (int) config('invoice.whatsapp.timeout', 20),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPayload(): array
    {
        $payload = [
            self::GROUP_INVOICE => [],
            self::GROUP_ZATCA => [],
            self::GROUP_WHATSAPP => [],
        ];

        foreach ($this->definitions() as $key => $definition) {
            $stored = AdminSetting::where('key', $key)->first();
            $value = $stored ? $this->decodeStoredValue($stored->value, $definition) : Arr::get($definition, 'default');
            $isSecret = (bool) Arr::get($definition, 'secret', false);

            $payload[$definition['group']][$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'value' => $isSecret ? null : $this->castForResponse($value, $definition['type']),
                'is_secret' => $isSecret,
                'is_configured' => $isSecret ? $this->hasSecretValue($stored?->value, $definition) : $value !== null && $value !== '',
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(array $settings): void
    {
        $definitions = $this->definitions();

        foreach ($settings as $key => $value) {
            if (! array_key_exists($key, $definitions)) {
                continue;
            }

            $definition = $definitions[$key];
            $isSecret = (bool) Arr::get($definition, 'secret', false);

            if ($isSecret && ($value === null || $value === '')) {
                continue;
            }

            $setting = AdminSetting::firstOrNew(['key' => $key]);
            $setting->type = $definition['type'];
            $setting->value = $this->prepareForStorage($value, $definition);
            $setting->save();
        }

        AdminSetting::clearCache();
    }

    public function seedDefaults(): void
    {
        foreach ($this->definitions() as $key => $definition) {
            $setting = AdminSetting::firstOrNew(['key' => $key]);
            $setting->type = $definition['type'];

            if (! $setting->exists && array_key_exists('default', $definition)) {
                $default = Arr::get($definition, 'default');
                $setting->value = $this->prepareForStorage($default, $definition);
            }

            $setting->save();
        }
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $definition = $this->definitions()[$key] ?? null;
        if (! $definition) {
            return $fallback;
        }

        $value = AdminSetting::getValue($key);
        if ($value === null || $value === '') {
            return Arr::exists($definition, 'default') ? $definition['default'] : $fallback;
        }

        if ((bool) Arr::get($definition, 'secret', false)) {
            return $this->decryptValue((string) $value, Arr::get($definition, 'default', $fallback));
        }

        return $this->castForResponse($value, $definition['type']);
    }

    public function hasSecret(string $key): bool
    {
        return (bool) Arr::get($this->definitions(), "{$key}.secret", false);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function prepareForStorage(mixed $value, array $definition): ?string
    {
        if ($value === null) {
            return null;
        }

        if ((bool) Arr::get($definition, 'secret', false)) {
            $stringValue = trim((string) $value);

            return $stringValue === '' ? null : Crypt::encryptString($stringValue);
        }

        return match ($definition['type']) {
            AdminSetting::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            AdminSetting::TYPE_NUMBER => (string) (is_numeric($value) ? $value : 0),
            AdminSetting::TYPE_JSON => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function decodeStoredValue(?string $storedValue, array $definition): mixed
    {
        if ($storedValue === null) {
            return null;
        }

        if ((bool) Arr::get($definition, 'secret', false)) {
            return $this->decryptValue($storedValue, null);
        }

        return $this->castForResponse($storedValue, $definition['type']);
    }

    private function decryptValue(string $value, mixed $fallback): mixed
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value ?: $fallback;
        }
    }

    private function hasSecretValue(?string $storedValue, array $definition): bool
    {
        if ($storedValue !== null && $storedValue !== '') {
            return true;
        }

        $default = Arr::get($definition, 'default');

        return $default !== null && $default !== '';
    }

    private function castForResponse(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            AdminSetting::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            AdminSetting::TYPE_NUMBER => is_numeric($value) ? (float) $value : 0,
            AdminSetting::TYPE_JSON => is_array($value) ? $value : json_decode((string) $value, true),
            default => $value,
        };
    }
}
