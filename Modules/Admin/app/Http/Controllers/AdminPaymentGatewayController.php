<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Models\AdminSetting;
use Modules\Payment\Models\PaymentMethod as PaymentMethodModel;
use Modules\Payment\Services\ActiveGatewayResolver;
use Modules\Payment\Services\PaymentService;

/**
 * Admin control over the ACTIVE payment gateway.
 *
 * Exactly one gateway processes real transactions at a time, chosen here and
 * persisted in admin_settings('active_payment_gateway'). Both gateways stay
 * registered and ready, so switching needs no code deploy. In-flight transactions
 * are unaffected — each one is settled by the gateway recorded on its own row.
 */
class AdminPaymentGatewayController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /**
     * GET /api/v1/admin/payment-gateways
     * List selectable gateways with their readiness + which one is active.
     */
    public function index(): JsonResponse
    {
        $active = ActiveGatewayResolver::name();

        $gateways = [];
        foreach (ActiveGatewayResolver::SELECTABLE as $key) {
            $gateway = $this->paymentService->getGateway($key);

            $gateways[] = [
                'key' => $key,
                'display_name' => $gateway?->getName() ?? ucfirst(str_replace('_', ' ', $key)),
                'registered' => $gateway !== null,
                'enabled' => $gateway?->isEnabled() ?? false,
                'configured' => $gateway ? $gateway->validateConfiguration() : false,
                'test_mode' => filter_var(config("payment.gateways.{$key}.test_mode", false), FILTER_VALIDATE_BOOLEAN),
                'secret_key_set' => (string) config("payment.gateways.{$key}.secret_key", '') !== '',
                'publishable_key_set' => (string) config("payment.gateways.{$key}.publishable_key", '') !== '',
                'is_active' => $key === $active,
            ];
        }

        return successResponse([
            'active_gateway' => $active,
            'gateways' => $gateways,
        ], 'Payment gateways retrieved successfully');
    }

    /**
     * PUT/POST /api/v1/admin/payment-gateways/active
     * Switch the active gateway. Body: { "gateway": "amazon_pay" | "moyasar" }.
     */
    public function setActive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => 'required|string|in:'.implode(',', ActiveGatewayResolver::SELECTABLE),
        ]);

        $key = strtolower(trim($validated['gateway']));
        $gateway = $this->paymentService->getGateway($key);

        if (! $gateway) {
            return errorResponse("Payment gateway '{$key}' is not registered. Enable it in config (e.g. set its *_ENABLED=true) before activating.", 422);
        }

        if (! $gateway->validateConfiguration()) {
            $testMode = filter_var(config("payment.gateways.{$key}.test_mode", false), FILTER_VALIDATE_BOOLEAN);
            $hint = match ($key) {
                'moyasar' => $testMode
                    ? 'Set MOYASAR_TEST_SECRET_KEY (and MOYASAR_TEST_PUBLISHABLE_KEY) in .env, then run: php artisan config:clear'
                    : 'Set MOYASAR_SECRET_KEY (and MOYASAR_PUBLISHABLE_KEY) in .env, or enable sandbox with MOYASAR_TEST_MODE=true and test keys, then run: php artisan config:clear',
                'amazon_pay' => $testMode
                    ? 'Set AMAZON_PAYMENT_TEST_MERCHANT_ID, AMAZON_PAYMENT_TEST_ACCESS_CODE, and SHA phrases in .env, then run: php artisan config:clear'
                    : 'Set AMAZON_PAYMENT_MERCHANT_ID, AMAZON_PAYMENT_ACCESS_CODE, and SHA phrases in .env, then run: php artisan config:clear',
                default => 'Add its API credentials in .env, then run: php artisan config:clear',
            };

            return errorResponse("Payment gateway '{$key}' is not fully configured. {$hint}", [
                'gateway' => $key,
                'test_mode' => $testMode,
                'secret_key_set' => (string) config("payment.gateways.{$key}.secret_key", '') !== '',
                'publishable_key_set' => (string) config("payment.gateways.{$key}.publishable_key", '') !== '',
                'required_env' => $key === 'moyasar'
                    ? ($testMode
                        ? ['MOYASAR_TEST_SECRET_KEY', 'MOYASAR_TEST_PUBLISHABLE_KEY']
                        : ['MOYASAR_SECRET_KEY', 'MOYASAR_PUBLISHABLE_KEY'])
                    : null,
            ], 422);
        }

        AdminSetting::setValue(ActiveGatewayResolver::SETTING_KEY, $key, AdminSetting::TYPE_TEXT);
        PaymentMethodModel::clearCache();

        return successResponse([
            'active_gateway' => ActiveGatewayResolver::name(),
        ], "Active payment gateway switched to '{$key}'");
    }
}
