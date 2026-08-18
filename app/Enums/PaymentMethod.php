<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH_ON_DELIVERY = 'cash_on_delivery';
    case VISA = 'visa';                         // Visa via Payfort
    case MASTERCARD = 'mastercard';            // MasterCard via Payfort
    case MADA = 'mada';                         // MADA via Payfort
    case Nathefah_WALLET = 'nazefah_wallet';
    case STC_PAY = 'stc_pay';                   // STC Pay via Payfort
    case APPLE_PAY = 'apple_pay';               // Apple Pay via Payfort
    case GOOGLE_PAY = 'google_pay';             // Google Pay via Payfort
    case SAMSUNG_PAY = 'samsung_pay';           // Samsung Pay via Payfort
    case CREDIT_CARD = 'credit_card';           // Moyasar UI aggregate (all card/wallet gateway methods)

    public const DIGITAL_PAYMENT = 'digital_payment';

    /**
     * Get all payment method values
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Values accepted from the client app, including public aliases.
     */
    public static function acceptedValues(): array
    {
        return array_values(array_unique([...self::values(), self::DIGITAL_PAYMENT]));
    }

    /**
     * Map client-facing aliases to the stored method key.
     */
    public static function normalize(string $value): string
    {
        return $value === self::DIGITAL_PAYMENT ? self::CREDIT_CARD->value : $value;
    }

    /**
     * Get translation key for this payment method
     */
    public function getTranslationKey(): string
    {
        return 'payment.'.$this->value;
    }

    /**
     * Get payment method with display name (uses translation)
     */
    public function getDisplayName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return __($this->getTranslationKey(), [], $locale);
    }

    /**
     * Get all payment methods with display names
     */
    public static function getAllWithDisplayNames(?string $lang = null): array
    {
        $lang = $lang ?? app()->getLocale();

        return array_map(function (PaymentMethod $method) use ($lang) {
            $type = in_array($method->value, ['cash_on_delivery', 'nazefah_wallet'], true)
                ? $method->value
                : \Modules\Payment\Services\ActiveGatewayResolver::name();

            return [
                'type' => $type,
                'value' => $method->value,
                'label' => $method->getDisplayName($lang),
                'payfort_option' => $method->getPayfortPaymentOption(),
            ];
        }, self::cases());
    }

    /**
     * Get gateway name from payment method value.
     *
     * cash_on_delivery / nazefah_wallet are settled in-app (no external gateway).
     * Every other method routes to the ADMIN-selected active gateway — DB-backed and
     * switchable at runtime without a deploy (amazon_pay <-> moyasar). Falls back to
     * the configured default (PAYMENT_GATEWAY) when no override is set.
     */
    public static function getGatewayName(string $paymentMethodValue): string
    {
        if (in_array($paymentMethodValue, ['cash_on_delivery', 'nazefah_wallet'], true)) {
            return $paymentMethodValue;
        }

        return \Modules\Payment\Services\ActiveGatewayResolver::name();
    }

    /**
     * Check if payment method value is valid
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::acceptedValues(), true);
    }

    /**
     * Get Payfort payment option for this method
     * Maps our payment methods to Payfort payment_option parameter
     */
    public function getPayfortPaymentOption(): ?string
    {
        return match ($this) {
            self::VISA => 'VISA',               // Visa cards
            self::MASTERCARD => 'MASTERCARD',   // MasterCard
            self::MADA => 'MADA',               // Saudi debit cards
            self::STC_PAY => 'STCPAY',          // STC Pay
            self::APPLE_PAY => 'APPLEPAY',      // Apple Pay
            self::GOOGLE_PAY => 'GOOGLEPAY',    // Google Pay
            self::SAMSUNG_PAY => 'SAMSUNGPAY',  // Samsung Pay
            default => null,                    // cash_on_delivery and wallet don't use Payfort
        };
    }

    /**
     * Check if this payment method routes through an external payment gateway
     * (Payfort/APS or Moyasar, whichever is active — see getGatewayName()).
     */
    public function requiresPayfort(): bool
    {
        return in_array($this->value, ['visa', 'mastercard', 'mada', 'stc_pay', 'apple_pay', 'google_pay', 'samsung_pay', 'credit_card']);
    }

    /**
     * Map this payment method to a Moyasar `source` type (parity with
     * getPayfortPaymentOption()). Returns null for methods Moyasar handles via the
     * hosted page's available sources rather than an explicit source selection.
     *
     * CONFIRM the exact set of sources enabled on your Moyasar account.
     */
    public function getMoyasarSource(): ?string
    {
        return match ($this) {
            self::VISA, self::MASTERCARD, self::MADA, self::CREDIT_CARD => 'creditcard',
            self::STC_PAY => 'stcpay',
            self::APPLE_PAY => 'applepay',
            self::SAMSUNG_PAY => 'samsungpay',
            default => null, 
        };
    }

    /**
     * Card schemes that support PayFort token_name reuse (remember_me at checkout).
     */
    public function supportsPayfortTokenization(): bool
    {
        return in_array($this, [self::VISA, self::MASTERCARD, self::MADA], true);
    }

    /**
     * Resolve the actual card brand from Moyasar's verified `source` object, so a
     * generic CREDIT_CARD selection can be corrected to the concrete scheme the
     * customer actually paid with (visa/mastercard/mada) once the gateway confirms it.
     *
     * ⚠️ CONFIRM `company` values against real Moyasar payloads — their docs use
     * "visa" / "master" / "mada" / "amex"; "mastercard" is accepted defensively too.
     */
    public static function resolveBrandFromMoyasarSource(?array $source): ?self
    {
        if (! $source) {
            return null;
        }

        $company = strtolower((string) ($source['company'] ?? ''));
        $type = strtolower((string) ($source['type'] ?? ''));

        return match (true) {
            $company === 'visa' => self::VISA,
            in_array($company, ['master', 'mastercard'], true) => self::MASTERCARD,
            $company === 'mada' || $type === 'mada' => self::MADA,
            default => null,
        };
    }
}
