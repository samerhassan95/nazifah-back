<?php

namespace Modules\Payment\Services;

/**
 * Resolves which payment gateway is ACTIVE for real transactions, right now.
 *
 * The active gateway is admin-controlled and DB-backed (an AdminSetting), so it
 * can be switched at runtime WITHOUT a code deploy or config:cache rebuild. The
 * checkout/payment flow calls this on every payment initiation
 * (PaymentMethod::getGatewayName()), so a switch takes effect on the next request.
 *
 * Resolution order:
 *   1. AdminSetting('active_payment_gateway')  — the admin toggle (cached by AdminSetting)
 *   2. config('payment.default')               — env/config fallback (PAYMENT_GATEWAY)
 *   3. 'amazon_pay'                             — hard fallback
 *
 * The value is validated against SELECTABLE so a typo or removed gateway can never
 * leave the platform pointing at a non-existent gateway.
 */
class ActiveGatewayResolver
{
    /** AdminSetting key holding the active gateway. */
    public const SETTING_KEY = 'active_payment_gateway';

    /**
     * Gateways an admin may select as the active card/redirect gateway.
     * Both must be registered in PaymentServiceProvider::createGateway().
     */
    public const SELECTABLE = ['amazon_pay', 'moyasar'];

    /** Safe hard fallback when nothing else resolves to a known gateway. */
    public const FALLBACK = 'amazon_pay';

    /**
     * The currently active gateway key (e.g. 'amazon_pay' or 'moyasar').
     */
    public static function name(): string
    {
        $configDefault = (string) config('payment.default', self::FALLBACK);
        $active = $configDefault;

        // DB-backed override. Guarded so the payment layer never hard-fails if the
        // Admin module/table is unavailable (e.g. during early migrations or console).
        try {
            if (class_exists(\Modules\Admin\Models\AdminSetting::class)) {
                $value = \Modules\Admin\Models\AdminSetting::getValue(self::SETTING_KEY, $configDefault);
                if (is_string($value) && trim($value) !== '') {
                    $active = $value;
                }
            }
        } catch (\Throwable $e) {
            // Fall back to the config default on any storage/cache error.
        }

        $active = strtolower(trim($active));

        return in_array($active, self::SELECTABLE, true) ? $active : self::FALLBACK;
    }
}
