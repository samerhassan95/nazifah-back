<?php

namespace Modules\Order\Services;

use App\Enums\PaymentMethod;
use App\Support\PaymentStatusPresenter;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Services\ActiveGatewayResolver;
use Modules\Order\Exceptions\InsufficientWalletBalanceException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderModificationIntent;
use Modules\Order\Models\OrderPayment;
use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\DTOs\RefundRequest;
use Modules\Payment\Models\PaymentRefund;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentService;

/**
 * Multi-leg payment engine shared by order creation, order editing
 * (surcharges) and the payment callback.
 *
 * An order can be settled by several legs paid with different methods. Wallet
 * and cash legs settle synchronously; each gateway leg redirects on its own and
 * settles when its callback arrives. The order is "fully paid" once the sum of
 * its paid legs covers final_amount.
 */
class OrderPaymentService
{
    public const CREDIT_CARD_ALIAS = 'credit_card';

    public function __construct(private PaymentService $paymentService) {}

    /**
     * Resolve a payment-gateway callback/cancel URL robustly.
     *
     * The payment routes are registered under a name prefix (api.payment.callback),
     * so route('payment.callback') is NOT defined and would throw. Try the bare
     * name, then the api-prefixed name, then fall back to the known path.
     */
    private function paymentRouteUrl(string $name, string $fallbackPath): string
    {
        foreach ([$name, 'api.'.$name] as $candidate) {
            if (\Illuminate\Support\Facades\Route::has($candidate)) {
                return route($candidate);
            }
        }

        return url($fallbackPath);
    }

    /**
     * Gateway methods a customer can use to pay a surcharge / split leg.
     */
    public function gatewayMethods(): array
    {
        return [
            PaymentMethod::VISA->value,
            PaymentMethod::MASTERCARD->value,
            PaymentMethod::MADA->value,
            PaymentMethod::STC_PAY->value,
            PaymentMethod::APPLE_PAY->value,
            PaymentMethod::SAMSUNG_PAY->value,
            PaymentMethod::CREDIT_CARD->value,
        ];
    }

    /**
     * Wallet aliases accepted on a split leg.
     */
    public function walletAliases(): array
    {
        return [PaymentMethod::Nathefah_WALLET->value, 'wallet'];
    }

    /**
     * Every method valid on a primary split leg: wallet + gateway methods.
     * Cash-on-delivery is excluded here — primary checkout treats COD as a
     * single-method "pay later" path, not a split leg.
     */
    public function allowedLegMethods(): array
    {
        return array_merge($this->walletAliases(), $this->gatewayMethods());
    }

    /**
     * Methods allowed when settling a price-difference (edit / vendor-review surcharge).
     * Includes cash_on_delivery so a visa order can finish the delta in cash (or COD
     * orders can finish the delta online).
     */
    public function allowedSurchargeMethods(): array
    {
        return array_values(array_unique(array_merge(
            $this->allowedLegMethods(),
            [PaymentMethod::CASH_ON_DELIVERY->value]
        )));
    }

    public function isWalletMethod(string $method): bool
    {
        return in_array($method, $this->walletAliases(), true);
    }

    public function isCodMethod(string $method): bool
    {
        return $method === PaymentMethod::CASH_ON_DELIVERY->value;
    }

    /**
     * Normalize payment_methods from the client: a single method string or an array.
     *
     * @return list<string>|null
     */
    public function normalizePaymentMethodsInput(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? [$this->normalizePaymentMethodAlias($value)] : null;
        }

        if (is_array($value)) {
            $methods = array_values(array_unique(array_filter(
                array_map(fn ($m) => $this->normalizePaymentMethodAlias(trim((string) $m)), $value),
                static fn ($m) => $m !== ''
            )));

            return count($methods) > 0 ? $methods : null;
        }

        return null;
    }

    /**
     * "credit_card" is the generic card option shown to the client when a gateway
     * supports it directly. Moyasar accepts it as-is (PaymentMethod::CREDIT_CARD maps
     * to its 'creditcard' source), so keep it unaliased there — storing/echoing the
     * actual choice instead of a card brand the customer never confirmed. Payfort/APS
     * has no generic card option and needs a concrete brand, so it still falls back
     * to VISA there.
     */
    private function normalizePaymentMethodAlias(string $method): string
    {
        if ($method === PaymentMethod::DIGITAL_PAYMENT) {
            $method = self::CREDIT_CARD_ALIAS;
        }

        if ($method !== self::CREDIT_CARD_ALIAS) {
            return $method;
        }

        return ActiveGatewayResolver::name() === 'moyasar'
            ? $method
            : PaymentMethod::VISA->value;
    }

    /**
     * @param  list<string>  $methods
     * @param  list<string>  $allowed
     */
    public function paymentMethodsAreAllowed(array $methods, array $allowed): bool
    {
        foreach ($methods as $method) {
            if (! in_array($method, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Spendable wallet balance after subtracting active holds (order reservations).
     */
    public function availableWalletBalance(int $clientId, bool $lock = false): float
    {
        $query = DB::table('clients')->where('id', $clientId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first(['wallet_balance', 'wallet_hold_amount']);
        if (! $row) {
            return 0.0;
        }

        return max(0.0, round((float) $row->wallet_balance - (float) ($row->wallet_hold_amount ?? 0), 2));
    }

    /**
     * Whether a payment method requires an online gateway checkout.
     */
    public function isGatewayMethod(string $method): bool
    {
        return in_array($method, $this->gatewayMethods(), true);
    }

    /**
     * Whether the split includes at least one gateway leg (wallet funds should be
     * reserved until that leg settles, not debited immediately).
     */
    public function splitHasGatewayLeg(array $legs): bool
    {
        foreach ($legs as $leg) {
            if ($this->isGatewayMethod($leg['payment_method'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a payment_methods[] split against the amount it must cover.
     *
     * Returns ['legs' => [['payment_method','amount'], ...], 'error' => null] on
     * success, or ['legs' => null, 'error' => '<lang-key>', 'params' => [...]] on
     * failure. Amounts are rounded to 2dp and the sum must equal $expectedTotal (±0.01).
     */
    public function normalizeSplit(array $paymentMethods, float $expectedTotal): array
    {
        if (empty($paymentMethods)) {
            return ['legs' => null, 'error' => 'order.split_requires_amounts', 'params' => []];
        }

        $legs = [];
        $sum = 0.0;

        foreach ($paymentMethods as $entry) {
            $method = is_array($entry) ? ($entry['payment_method'] ?? null) : null;
            $amount = is_array($entry) ? ($entry['amount'] ?? null) : null;

            if (! is_string($method) || ! in_array($method, $this->allowedLegMethods(), true)) {
                return ['legs' => null, 'error' => 'order.split_invalid_method', 'params' => ['method' => (string) $method]];
            }
            if (! is_numeric($amount) || round((float) $amount, 2) <= 0) {
                return ['legs' => null, 'error' => 'order.split_requires_amounts', 'params' => []];
            }

            $amount = round((float) $amount, 2);
            $legs[] = ['payment_method' => $method, 'amount' => $amount];
            $sum += $amount;
        }

        if (abs(round($sum, 2) - round($expectedTotal, 2)) > 0.01) {
            return [
                'legs' => null,
                'error' => 'order.split_amount_mismatch',
                'params' => ['expected' => number_format($expectedTotal, 2)],
            ];
        }

        // Sync legs (wallet) first so an insufficient balance fails fast, before any
        // external gateway leg is initialised (gateway init is not transactional).
        usort($legs, fn ($a, $b) => ($this->isWalletMethod($a['payment_method']) ? 0 : 1)
            <=> ($this->isWalletMethod($b['payment_method']) ? 0 : 1));

        return ['legs' => $legs, 'error' => null];
    }

    /**
     * Allocate a surcharge across the methods the customer CHOSE — the backend owns
     * all amounts; the client never sends prices. Wallet is consumed first (up to the
     * available balance), and the remainder goes to the first gateway method, or to
     * cash_on_delivery when that is the chosen remainder method.
     *
     * @param  array  $methods  list of method names (strings), no amounts
     */
    public function allocateSurcharge(array $methods, float $delta, float $walletBalance): array
    {
        $delta = round($delta, 2);
        $methods = array_values(array_unique(array_map('strval', $methods)));

        if (empty($methods)) {
            return ['legs' => null, 'error' => 'order.split_requires_amounts', 'params' => []];
        }
        foreach ($methods as $m) {
            if (! in_array($m, $this->allowedSurchargeMethods(), true)) {
                return ['legs' => null, 'error' => 'order.split_invalid_method', 'params' => ['method' => $m]];
            }
        }

        $hasWallet = (bool) array_filter($methods, fn ($m) => $this->isWalletMethod($m));
        $gatewayMethods = array_values(array_filter(
            $methods,
            fn ($m) => $this->isGatewayMethod($m)
        ));
        $hasCod = (bool) array_filter($methods, fn ($m) => $this->isCodMethod($m));

        $legs = [];
        $remaining = $delta;

        // Wallet first, up to whatever balance is available.
        if ($hasWallet) {
            $walletAmount = min($remaining, round($walletBalance, 2));
            if ($walletAmount > 0) {
                $legs[] = ['payment_method' => PaymentMethod::Nathefah_WALLET->value, 'amount' => round($walletAmount, 2)];
                $remaining = round($remaining - $walletAmount, 2);
            }
        }

        // Remainder → gateway first (if chosen), else COD.
        if (bccomp(number_format($remaining, 2, '.', ''), '0', 2) > 0) {
            if (! empty($gatewayMethods)) {
                $legs[] = ['payment_method' => $gatewayMethods[0], 'amount' => round($remaining, 2)];
            } elseif ($hasCod) {
                $legs[] = [
                    'payment_method' => PaymentMethod::CASH_ON_DELIVERY->value,
                    'amount' => round($remaining, 2),
                ];
            } else {
                // Wallet-only selection that can't cover the delta — caller returns 402.
                return ['legs' => null, 'error' => 'order.insufficient_wallet_balance_short', 'params' => []];
            }
        }

        if (empty($legs)) {
            return ['legs' => null, 'error' => 'order.split_requires_amounts', 'params' => []];
        }

        return ['legs' => $legs, 'error' => null];
    }

    /**
     * Settle a normalized split across legs against $order.
     *
     * Wallet legs settle synchronously; each gateway leg returns its own payment
     * link (the client pays them one at a time). Wallet sufficiency for the WHOLE
     * split is checked up front so we never half-settle then fail.
     *
     * @param  array  $legs  normalized [['payment_method','amount'], ...]
     * @return array{gateway_payments: array, paid_total: float, fully_paid: bool}
     *
     * @throws InsufficientWalletBalanceException when wallet legs exceed the balance.
     * @throws \RuntimeException ('order.payment_init_failed') when a gateway leg fails to init.
     */
    public function settleSplitLegs(Order $order, array $legs, $client, array $opts = []): array
    {
        $isSurcharge = (bool) ($opts['is_surcharge'] ?? false);

        // Up-front wallet check across all wallet legs in this split.
        $walletNeeded = 0.0;
        foreach ($legs as $leg) {
            if ($this->isWalletMethod($leg['payment_method'])) {
                $walletNeeded += $leg['amount'];
            }
        }
        if ($walletNeeded > 0) {
            $available = $this->availableWalletBalance((int) $client->id, lock: true);
            if ($available + 0.01 < $walletNeeded) {
                throw new InsufficientWalletBalanceException(round($walletNeeded, 2), round($available, 2));
            }
        }

        $originalMethod = $opts['original_method'] ?? $order->payment_method;
        $gatewayPayments = [];
        $sequence = 0;
        $reserveWalletUntilGatewayPays = $this->splitHasGatewayLeg($legs);

        foreach ($legs as $leg) {
            $method = $leg['payment_method'];
            $amount = $leg['amount'];

            $legOpts = [
                'is_surcharge' => $isSurcharge,
                'modification_intent_id' => $opts['modification_intent_id'] ?? null,
                'sequence' => $sequence++,
                'original_method' => $originalMethod,
                'authorization_type' => $originalMethod === $method ? 'supplemental' : 'alternative',
                'reference_prefix' => $isSurcharge ? 'ADD' : 'LEG',
                'meta' => $opts['meta'] ?? null,
            ];

            if ($this->isWalletMethod($method)) {
                if ($reserveWalletUntilGatewayPays) {
                    $this->reserveWalletLeg($order, $amount, $client, $legOpts);
                } else {
                    $this->settleWalletLeg($order, $amount, $client, $legOpts);
                }

                continue;
            }

            if ($method === PaymentMethod::CASH_ON_DELIVERY->value) {
                $this->recordCodLeg($order, $amount, $legOpts);

                continue;
            }

            $result = $this->createGatewayLeg($order, $amount, $method, $client, $legOpts);
            if ($result === null) {
                throw new \RuntimeException('order.payment_init_failed');
            }
            $gatewayPayments[] = $result['payment'];
        }

        return [
            'gateway_payments' => $gatewayPayments,
            'paid_total' => $this->paidTotal($order),
            'fully_paid' => $this->isFullyPaid($order),
        ];
    }

    /**
     * Build a client-facing payment breakdown for split checkout or surcharge.
     *
     * @param  array<int, array<string, mixed>>  $gatewayPayments
     * @return array<string, mixed>
     */
    public function buildSplitPaymentResponse(
        Order $order,
        array $gatewayPayments = [],
        ?float $amountDue = null,
        ?int $modificationIntentId = null,
        bool $staged = false,
        ?bool $isSurcharge = null
    ): array {
        $query = OrderPayment::with('paymentTransaction')
            ->where('order_id', $order->id);

        if ($isSurcharge !== null) {
            $query->where('is_surcharge', $isSurcharge);
        }

        if ($modificationIntentId !== null) {
            $query->where('modification_intent_id', $modificationIntentId);
        }

        $legs = $query->orderBy('sequence')->get();

        $walletPayments = [];
        foreach ($legs as $leg) {
            if (! $this->isWalletMethod($leg->payment_method)) {
                continue;
            }

            $tx = $leg->paymentTransaction;
            // "Held" means funds are actually reserved. A deferred (no-hold) leg is
            // pending but places NO hold, so is_held must be false for it — otherwise
            // the client thinks money is blocked when the wallet is fully spendable.
            $holdPlaced = (bool) ($leg->meta['wallet_hold_placed'] ?? $tx?->response_data['wallet_hold_placed'] ?? true);
            $isHeld = $holdPlaced
                && $leg->status === OrderPayment::STATUS_PENDING
                && (bool) ($leg->meta['wallet_reserved'] ?? $tx?->response_data['wallet_reserved'] ?? false);

            $walletPayments[] = [
                'transaction_id' => $tx?->transaction_id,
                'amount' => (float) $leg->amount,
                'currency' => $tx?->currency ?? 'SAR',
                'gateway' => 'wallet',
                'payment_method' => $leg->payment_method,
                'status' => $leg->status,
                'is_held' => $isHeld,
                // Pending-but-not-held wallet legs are debited when the gateway leg pays.
                'charged_after_gateway' => ! $holdPlaced && $leg->status === OrderPayment::STATUS_PENDING,
                'authorization_type' => $tx?->authorization_type,
                'paid_at' => $leg->paid_at?->format('c'),
            ];
        }

        if (empty($gatewayPayments)) {
            foreach ($legs as $leg) {
                if ($this->isWalletMethod($leg->payment_method)) {
                    continue;
                }

                $tx = $leg->paymentTransaction;
                if (! $tx) {
                    continue;
                }

                $gatewayPayments[] = [
                    'transaction_id' => $tx->transaction_id,
                    'amount' => (float) $leg->amount,
                    'currency' => $tx->currency ?? 'SAR',
                    'gateway' => $tx->gateway,
                    'payment_method' => $leg->payment_method,
                    'authorization_type' => $tx->authorization_type,
                    'status' => $leg->status,
                    'payment_url' => $tx->response_data['payment_url'] ?? null,
                    'payment_params' => $tx->response_data['payment_params'] ?? null,
                    'redirect_instructions' => $tx->response_data['redirect_instructions'] ?? null,
                ];
            }
        }

        $paymentLegs = $legs->map(function ($leg) {
            $isWallet = $this->isWalletMethod($leg->payment_method);
            // No-hold legs are pending but not held (see wallet_payments above).
            $holdPlaced = (bool) ($leg->meta['wallet_hold_placed'] ?? true);

            return [
                'payment_method' => $leg->payment_method,
                'amount' => (float) $leg->amount,
                'status' => $leg->status,
                'sequence' => (int) $leg->sequence,
                'is_surcharge' => (bool) $leg->is_surcharge,
                'is_held' => $isWallet && $holdPlaced && $leg->status === OrderPayment::STATUS_PENDING,
            ];
        })->values()->all();

        $walletAmount = round(array_sum(array_column($walletPayments, 'amount')), 2);
        $gatewayAmount = round(array_sum(array_map(
            fn ($p) => (float) ($p['amount'] ?? 0),
            $gatewayPayments
        )), 2);

        $response = [
            'gateway_payments' => array_values($gatewayPayments),
            'wallet_payments' => $walletPayments,
            'payment_legs' => $paymentLegs,
            'summary' => [
                'total_due' => $amountDue !== null ? round($amountDue, 2) : round($walletAmount + $gatewayAmount, 2),
                'wallet_amount' => $walletAmount,
                'gateway_amount' => $gatewayAmount,
                'wallet_held' => collect($walletPayments)->contains(fn ($p) => ($p['is_held'] ?? false) === true),
                'wallet_paid' => collect($walletPayments)->contains(fn ($p) => ($p['status'] ?? '') === OrderPayment::STATUS_PAID),
                'gateway_pending' => collect($paymentLegs)->contains(fn ($p) => ! $this->isWalletMethod($p['payment_method']) && ($p['status'] ?? '') === OrderPayment::STATUS_PENDING),
            ],
        ];

        if ($amountDue !== null) {
            $response['amount_due'] = round($amountDue, 2);
        }

        if ($modificationIntentId !== null) {
            $response['modification_intent_id'] = $modificationIntentId;
        }

        if ($staged) {
            $response['staged'] = true;
        }

        return $response;
    }

    /**
     * Compact payment breakdown for order detail / tracking UIs.
     * Only returns payment methods that were actually used (amount > 0).
     *
     * @return array{
     *     payment_method: ?string,
     *     final_amount: float,
     *     total_amount: float,
     *     is_split_payment: bool,
     *     currency: string,
     *     payments: list<array{
     *         payment_method: string,
     *         payment_method_label: string,
     *         amount: float,
     *         status: string,
     *         status_label: string
     *     }>
     * }
     */
    public function buildOrderPaymentBreakdownForApi(Order $order): array
    {
        $finalAmount = round((float) $order->final_amount, 2);
        $usePaidLegsOnly = ($order->payment_status ?? 'pending') === 'paid';
        $locale = app()->getLocale();

        $query = OrderPayment::where('order_id', $order->id)
            ->orderBy('sequence');

        if ($usePaidLegsOnly) {
            $query->where('status', OrderPayment::STATUS_PAID);
        } else {
            $query->whereNotIn('status', [
                OrderPayment::STATUS_FAILED,
                OrderPayment::STATUS_CANCELLED,
                OrderPayment::STATUS_REFUNDED,
            ]);
        }

        $legs = $query->get();
        $paymentsByMethod = [];

        foreach ($legs as $leg) {
            $method = (string) $leg->payment_method;
            // A partial refund (e.g. vendor rejected items and the difference was
            // refunded) keeps the leg at status=paid — markLegPartialRefund() only
            // flips status to refunded once the leg is refunded in full — and never
            // touches `amount` itself, tracking the refunded portion in
            // meta.refunded_amount instead. Net it out here so a partially-refunded
            // leg doesn't keep showing its original, pre-refund amount.
            $amount = $this->refundableAmountOnLeg($leg);

            if ($amount <= 0) {
                continue;
            }

            if (! isset($paymentsByMethod[$method])) {
                $paymentsByMethod[$method] = [
                    'payment_method' => $method,
                    'payment_method_label' => $this->paymentMethodLabel($method, $locale),
                    'amount' => 0.0,
                    'status' => (string) $leg->status,
                ];
            }

            $paymentsByMethod[$method]['amount'] = round(
                $paymentsByMethod[$method]['amount'] + $amount,
                2
            );
            $paymentsByMethod[$method]['status'] = $this->mergeBreakdownLegStatus(
                $paymentsByMethod[$method]['status'],
                (string) $leg->status
            );
        }

        if ($paymentsByMethod === []) {
            $method = (string) ($order->payment_method ?? '');
            if ($method !== '' && $finalAmount > 0) {
                $status = ($order->payment_status ?? 'pending') === 'paid'
                    ? OrderPayment::STATUS_PAID
                    : OrderPayment::STATUS_PENDING;

                $paymentsByMethod[$method] = [
                    'payment_method' => $method,
                    'payment_method_label' => $this->paymentMethodLabel($method, $locale),
                    'amount' => $finalAmount,
                    'status' => $status,
                ];
            }
        }

        $payments = collect($paymentsByMethod)
            ->values()
            ->map(function (array $payment) {
                $payment['amount'] = round((float) $payment['amount'], 2);
                $payment['status_label'] = PaymentStatusPresenter::label($payment['status']);

                return $payment;
            })
            ->values()
            ->all();

        $totalAmount = round(array_sum(array_column($payments, 'amount')), 2);

        return [
            'payment_method' => $order->payment_method,
            'final_amount' => $finalAmount,
            'total_amount' => $totalAmount,
            'is_split_payment' => count($payments) > 1,
            'currency' => 'SAR',
            'payments' => $payments,
        ];
    }

    private function paymentMethodLabel(string $method, string $locale): string
    {
        $enum = PaymentMethod::tryFrom($method);

        return $enum
            ? $enum->getDisplayName($locale)
            : $method;
    }

    private function mergeBreakdownLegStatus(string $current, string $incoming): string
    {
        if ($current === OrderPayment::STATUS_PENDING || $incoming === OrderPayment::STATUS_PENDING) {
            return OrderPayment::STATUS_PENDING;
        }

        if ($current === OrderPayment::STATUS_FAILED || $incoming === OrderPayment::STATUS_FAILED) {
            return OrderPayment::STATUS_FAILED;
        }

        if ($current === OrderPayment::STATUS_REFUNDED || $incoming === OrderPayment::STATUS_REFUNDED) {
            return OrderPayment::STATUS_REFUNDED;
        }

        if ($current === OrderPayment::STATUS_CANCELLED || $incoming === OrderPayment::STATUS_CANCELLED) {
            return OrderPayment::STATUS_CANCELLED;
        }

        return OrderPayment::STATUS_PAID;
    }

    /**
     * @deprecated Use buildSplitPaymentResponse() — kept as alias for order-update surcharges.
     *
     * @param  array<int, array<string, mixed>>  $gatewayPayments
     * @return array<string, mixed>
     */
    public function buildSurchargePaymentResponse(
        Order $order,
        ?int $modificationIntentId,
        array $gatewayPayments = [],
        ?float $amountDue = null,
        bool $staged = false
    ): array {
        return $this->buildSplitPaymentResponse(
            $order,
            $gatewayPayments,
            $amountDue,
            $modificationIntentId,
            $staged,
            true
        );
    }

    /**
     * Sum of PAID legs that belong to a specific modification intent (surcharge).
     * Used to apply a staged total only once the surcharge split is fully covered.
     */
    public function surchargePaidTotal(int $modificationIntentId): float
    {
        return (float) OrderPayment::where('modification_intent_id', $modificationIntentId)
            ->where('status', OrderPayment::STATUS_PAID)
            ->sum('amount');
    }

    /**
     * Settle a wallet leg immediately: debit the client wallet, record a wallet
     * ledger entry, a completed PaymentTransaction and a paid OrderPayment leg.
     *
     * @throws InsufficientWalletBalanceException when the balance is too low.
     */
    /**
     * Record the wallet leg of a split WITHOUT holding funds. Like the checkout
     * flow, no wallet_hold_amount is placed — the wallet stays fully spendable until
     * the gateway leg is paid, at which point captureReservedWalletLeg() re-checks
     * the balance and debits all-or-nothing. The pre-check below only rejects an
     * obviously-underfunded split early (it reads the balance, it does not hold).
     */
    public function reserveWalletLeg(Order $order, float $amount, $client, array $opts = []): OrderPayment
    {
        $amount = round((float) $amount, 2);
        $available = $this->availableWalletBalance((int) $client->id, lock: true);

        if ($available + 0.01 < $amount) {
            throw new InsufficientWalletBalanceException($amount, $available);
        }

        $reference = 'WALLET-DEFER-'.$order->id.'-'.($opts['sequence'] ?? 0).'-'.uniqid();

        DB::table('wallet_transactions')->insert([
            'client_id' => $client->id,
            'type' => 'debit',
            'amount' => $amount,
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'description' => ($opts['is_surcharge'] ?? false)
                ? 'Order #'.$order->order_number.' - surcharge (wallet, awaiting gateway payment)'
                : 'Order #'.$order->order_number.' - payment (wallet, awaiting gateway payment)',
            'order_id' => $order->id,
            'transaction_id' => $reference,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'transaction_id' => $reference,
            'amount' => $amount,
            'currency' => 'SAR',
            'status' => 'pending',
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'is_additional_charge' => (bool) ($opts['is_surcharge'] ?? false),
            'authorization_type' => $opts['authorization_type'] ?? null,
            'customer_email' => $client->email ?? null,
            'customer_name' => $client->full_name ?? $client->name ?? null,
            'customer_phone' => $client->phone ?? null,
            'response_data' => [
                'order_id' => $order->id,
                'modification_intent_id' => $opts['modification_intent_id'] ?? null,
                'is_additional_charge' => (bool) ($opts['is_surcharge'] ?? false),
                'wallet_reserved' => true,
                // No wallet_hold_amount placed — debit is deferred to the gateway
                // webhook. capture/release read this to skip the global hold counter.
                'wallet_hold_placed' => false,
            ],
        ]);

        return OrderPayment::create([
            'order_id' => $order->id,
            'payment_transaction_id' => $transaction->id,
            'modification_intent_id' => $opts['modification_intent_id'] ?? null,
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'amount' => $amount,
            'status' => OrderPayment::STATUS_PENDING,
            'sequence' => (int) ($opts['sequence'] ?? 0),
            'is_surcharge' => (bool) ($opts['is_surcharge'] ?? false),
            'meta' => array_merge($opts['meta'] ?? [], ['wallet_reserved' => true, 'wallet_hold_placed' => false]),
        ]);
    }

    /**
     * Finalize all pending wallet reservations for an order (after gateway payment).
     */
    public function captureOrderWalletReservations(int $orderId): void
    {
        $legs = OrderPayment::with('paymentTransaction')
            ->where('order_id', $orderId)
            ->where('payment_method', PaymentMethod::Nathefah_WALLET->value)
            ->where('status', OrderPayment::STATUS_PENDING)
            ->get();

        foreach ($legs as $leg) {
            $this->captureReservedWalletLeg($leg);
        }
    }

    /**
     * Release pending wallet reservations for an order (cancel / gateway failure).
     *
     * @param  int|null  $modificationIntentId  When set, only surcharge legs for that intent are released.
     * @return array<int, array<string, mixed>>
     */
    public function releaseOrderWalletReservations(int $orderId, ?int $modificationIntentId = null): array
    {
        $query = OrderPayment::with('paymentTransaction')
            ->where('order_id', $orderId)
            ->where('payment_method', PaymentMethod::Nathefah_WALLET->value)
            ->where('status', OrderPayment::STATUS_PENDING);

        if ($modificationIntentId !== null) {
            $query->where('modification_intent_id', $modificationIntentId);
        }

        $releasedLines = [];
        foreach ($query->get() as $leg) {
            $amount = round((float) $leg->amount, 2);
            $this->releaseReservedWalletLeg($leg);
            if ($amount > 0) {
                $releasedLines[] = [
                    'amount' => $amount,
                    'method' => 'wallet',
                    'payment_method' => PaymentMethod::Nathefah_WALLET->value,
                    'gateway_attempted' => false,
                    'gateway_failed' => false,
                    'gateway_failure_message' => null,
                ];
            }
        }

        return $releasedLines;
    }

    /**
     * Convert a reserved wallet leg into an actual debit.
     */
    public function captureReservedWalletLeg(OrderPayment $leg): void
    {
        if ($leg->status !== OrderPayment::STATUS_PENDING) {
            return;
        }

        $order = $leg->order;
        if (! $order) {
            return;
        }

        $amount = round((float) $leg->amount, 2);
        $clientId = (int) $order->client_id;

        // Legacy/held legs reserved funds up front (wallet_hold_amount) and only need
        // the hold released as the balance is debited. New split-checkout legs place
        // NO hold — the wallet is left untouched until the card payment's webhook lands
        // here, so the balance must be re-checked now (all-or-nothing).
        $holdPlaced = (bool) ($leg->meta['wallet_hold_placed'] ?? true);

        DB::table('clients')->where('id', $clientId)->lockForUpdate()->first();

        if (! $holdPlaced) {
            $available = $this->availableWalletBalance($clientId);

            // All-or-nothing: if the balance can no longer cover the wallet portion
            // (spent elsewhere between checkout and this webhook), leave the wallet
            // untouched and record the shortfall as amount_remaining.
            if ($available + 0.01 < $amount) {
                $this->cancelUnfundedWalletLeg($leg, $order);

                return;
            }

            DB::table('clients')->where('id', $clientId)->decrement('wallet_balance', $amount);
        } else {
            $hold = (float) (DB::table('clients')->where('id', $clientId)->value('wallet_hold_amount') ?? 0);
            $releaseHold = min($amount, $hold);

            if ($releaseHold > 0) {
                DB::table('clients')->where('id', $clientId)->decrement('wallet_hold_amount', $releaseHold);
            }

            DB::table('clients')->where('id', $clientId)->decrement('wallet_balance', $amount);
        }

        if ($leg->payment_transaction_id) {
            PaymentTransaction::whereKey($leg->payment_transaction_id)->update([
                'status' => 'completed',
                'paid_at' => now(),
            ]);
        }

        if ($leg->paymentTransaction?->transaction_id) {
            DB::table('wallet_transactions')
                ->where('transaction_id', $leg->paymentTransaction->transaction_id)
                ->update(['status' => 'completed', 'updated_at' => now()]);
        }

        OrderPayment::whereKey($leg->id)
            ->where('status', OrderPayment::STATUS_PENDING)
            ->update(['status' => OrderPayment::STATUS_PAID, 'paid_at' => now()]);
    }

    /**
     * A deferred (no-hold) wallet leg whose balance is no longer sufficient at
     * capture time. Leave the wallet untouched, cancel the leg and its records, and
     * record the uncovered amount on the order as amount_remaining so it awaits the
     * remaining payment (source of truth = order.amount_remaining).
     */
    private function cancelUnfundedWalletLeg(OrderPayment $leg, Order $order): void
    {
        if ($leg->payment_transaction_id) {
            PaymentTransaction::whereKey($leg->payment_transaction_id)->update(['status' => 'cancelled']);
        }

        if ($leg->paymentTransaction?->transaction_id) {
            DB::table('wallet_transactions')
                ->where('transaction_id', $leg->paymentTransaction->transaction_id)
                ->update(['status' => 'failed', 'updated_at' => now()]);
        }

        OrderPayment::whereKey($leg->id)
            ->where('status', OrderPayment::STATUS_PENDING)
            ->update(['status' => OrderPayment::STATUS_CANCELLED]);

        // Only PRIMARY legs feed amount_remaining. A cancelled SURCHARGE wallet leg
        // just leaves its modification intent short of full payment, so the staged
        // edit is never applied (final_amount stays put) — recomputing amount_remaining
        // against an un-bumped total here would understate what the order owes.
        if (! $leg->is_surcharge) {
            $order->refresh();
            $remaining = round(max(0.0, (float) $order->final_amount - $this->paidTotal($order)), 2);
            $order->update(['amount_remaining' => $remaining]);
        }

        \Illuminate\Support\Facades\Log::warning('Wallet portion unfunded at capture — leg cancelled', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'is_surcharge' => (bool) $leg->is_surcharge,
            'wallet_amount' => round((float) $leg->amount, 2),
        ]);
    }

    /**
     * Cancel a reserved wallet leg and return held funds to the spendable balance.
     */
    public function releaseReservedWalletLeg(OrderPayment $leg): void
    {
        if ($leg->status !== OrderPayment::STATUS_PENDING) {
            return;
        }

        $order = $leg->order;
        if (! $order) {
            return;
        }

        $amount = round((float) $leg->amount, 2);
        $clientId = (int) $order->client_id;

        // Only release a hold if one was actually placed. Deferred (no-hold) legs
        // never incremented wallet_hold_amount, so decrementing it here would wrongly
        // release another order's hold from the shared counter.
        $holdPlaced = (bool) ($leg->meta['wallet_hold_placed'] ?? true);

        DB::table('clients')->where('id', $clientId)->lockForUpdate()->first();

        if ($holdPlaced) {
            $hold = (float) (DB::table('clients')->where('id', $clientId)->value('wallet_hold_amount') ?? 0);
            $releaseHold = min($amount, $hold);

            if ($releaseHold > 0) {
                DB::table('clients')->where('id', $clientId)->decrement('wallet_hold_amount', $releaseHold);
            }
        }

        if ($leg->payment_transaction_id) {
            PaymentTransaction::whereKey($leg->payment_transaction_id)->update(['status' => 'cancelled']);
        }

        if ($leg->paymentTransaction?->transaction_id) {
            DB::table('wallet_transactions')
                ->where('transaction_id', $leg->paymentTransaction->transaction_id)
                ->update(['status' => 'failed', 'updated_at' => now()]);
        }

        OrderPayment::whereKey($leg->id)
            ->where('status', OrderPayment::STATUS_PENDING)
            ->update(['status' => OrderPayment::STATUS_CANCELLED]);
    }

    public function settleWalletLeg(Order $order, float $amount, $client, array $opts = []): OrderPayment
    {
        $amount = round((float) $amount, 2);
        $available = $this->availableWalletBalance((int) $client->id, lock: true);

        if ($available + 0.01 < $amount) {
            throw new InsufficientWalletBalanceException($amount, $available);
        }

        DB::table('clients')->where('id', $client->id)->decrement('wallet_balance', $amount);

        $reference = 'WALLET-'.$order->id.'-'.($opts['sequence'] ?? 0).'-'.uniqid();

        DB::table('wallet_transactions')->insert([
            'client_id' => $client->id,
            'type' => 'debit',
            'amount' => $amount,
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'description' => ($opts['is_surcharge'] ?? false)
                ? 'Order #'.$order->order_number.' - surcharge (wallet)'
                : 'Order #'.$order->order_number.' - payment (wallet)',
            'order_id' => $order->id,
            'transaction_id' => $reference,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'transaction_id' => $reference,
            'amount' => $amount,
            'currency' => 'SAR',
            'status' => 'completed',
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'is_additional_charge' => (bool) ($opts['is_surcharge'] ?? false),
            'authorization_type' => $opts['authorization_type'] ?? null,
            'customer_email' => $client->email ?? null,
            'customer_name' => $client->full_name ?? $client->name ?? null,
            'customer_phone' => $client->phone ?? null,
            'paid_at' => now(),
            'response_data' => [
                'order_id' => $order->id,
                'modification_intent_id' => $opts['modification_intent_id'] ?? null,
                'is_additional_charge' => (bool) ($opts['is_surcharge'] ?? false),
            ],
        ]);

        return OrderPayment::create([
            'order_id' => $order->id,
            'payment_transaction_id' => $transaction->id,
            'modification_intent_id' => $opts['modification_intent_id'] ?? null,
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'amount' => $amount,
            'status' => OrderPayment::STATUS_PAID,
            'sequence' => (int) ($opts['sequence'] ?? 0),
            'is_surcharge' => (bool) ($opts['is_surcharge'] ?? false),
            'paid_at' => now(),
            'meta' => $opts['meta'] ?? null,
        ]);
    }

    /**
     * Record a cash-on-delivery leg. No money moves now; it is collected at
     * delivery, but it counts toward order coverage so the order can advance.
     */
    public function recordCodLeg(Order $order, float $amount, array $opts = []): OrderPayment
    {
        $amount = round((float) $amount, 2);

        return OrderPayment::create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethod::CASH_ON_DELIVERY->value,
            'amount' => $amount,
            'status' => OrderPayment::STATUS_PAID, // committed; cash collected on delivery
            'sequence' => (int) ($opts['sequence'] ?? 0),
            'is_surcharge' => (bool) ($opts['is_surcharge'] ?? false),
            'meta' => array_merge(['cod' => true], $opts['meta'] ?? []),
        ]);
    }

    /**
     * Build a gateway payment link for one leg and persist a pending
     * PaymentTransaction + pending OrderPayment leg. Returns null if the leg's
     * method is not a gateway method or the gateway init fails.
     *
     * @return array{order_payment: OrderPayment, transaction: PaymentTransaction, payment: array}|null
     */
    public function createGatewayLeg(Order $order, float $amount, string $paymentMethod, $client, array $opts = []): ?array
    {
        $amount = round((float) $amount, 2);
        $methodEnum = PaymentMethod::tryFrom($paymentMethod);

        if (! $methodEnum || ! $methodEnum->requiresPayfort()) {
            return null;
        }

        $gateway = PaymentMethod::getGatewayName($paymentMethod);
        // STC Pay settles on its own merchant account; passing the STCPAY payment
        // option lets the gateway resolve that merchant context automatically.
        $paymentOption = $methodEnum->getPayfortPaymentOption();

        $this->paymentService->setGateway($gateway);

        // Unique reference; the gateway echoes this back as merchant_reference and
        // the callback matches the exact leg by it (transaction_id is unique).
        // Prefix distinguishes a primary split leg (LEG) from a surcharge (ADD).
        // The per-leg sequence guards against a uniqid() collision when several
        // gateway legs of one split are created within the same microsecond.
        $prefix = $opts['reference_prefix'] ?? (($opts['is_surcharge'] ?? true) ? 'ADD' : 'LEG');
        $reference = $order->order_number.'-'.$prefix.'-'.($opts['sequence'] ?? 0).'-'.uniqid();

        $customerEmail = $client->email ?? config('payment.default_customer_email', 'noreply@nathefah.com');
        $customerName = $client->full_name ?? $client->name ?? 'Customer';
        $customerPhone = $client->phone ?? null;

        $returnUrl = $this->paymentRouteUrl('payment.callback', '/api/v1/payments/callback').'?order_id='.$order->id;
        $cancelUrl = $this->paymentRouteUrl('payment.cancel', '/api/v1/payments/cancel').'?order_id='.$order->id;

        $intentId = $opts['modification_intent_id'] ?? null;
        $isSurcharge = (bool) ($opts['is_surcharge'] ?? true);

        // supplemental = same method as the original authorization, alternative =
        // a different method (e.g. paying with a second card or the wallet).
        $authorizationType = $opts['authorization_type'] ?? (
            isset($opts['original_method'])
                ? ($opts['original_method'] === $paymentMethod ? 'supplemental' : 'alternative')
                : null
        );

        $paymentRequest = new PaymentRequest(
            amount: $amount,
            currency: 'SAR',
            orderId: $reference,
            customerEmail: $customerEmail,
            customerName: $customerName,
            customerPhone: $customerPhone,
            returnUrl: $returnUrl,
            cancelUrl: $cancelUrl,
            paymentOption: $paymentOption,
            metadata: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'client_id' => $order->client_id,
                'payment_option' => $paymentOption,
                'is_additional_charge' => $isSurcharge,
                'modification_intent_id' => $intentId,
            ],
        );

        $response = $this->paymentService->initializePayment($paymentRequest);

        if (! $response->isSuccessful()) {
            return null;
        }

        // STC Pay must keep its own payment option, never silently fall back.
        $paymentParams = $response->data['payment_params'] ?? null;
        if (
            $paymentMethod === PaymentMethod::STC_PAY->value
            && $paymentParams !== null
            && (($paymentParams['payment_option'] ?? null) !== 'STCPAY')
        ) {
            return null;
        }

        $gatewayObj = $this->paymentService->getActiveGateway();

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => $gatewayObj->getName(),
            'transaction_id' => $response->transactionId,
            'amount' => $amount,
            'currency' => $response->currency ?? 'SAR',
            'status' => $response->status ?? 'pending',
            'payment_method' => $paymentMethod,
            'is_additional_charge' => $isSurcharge,
            'authorization_type' => $authorizationType,
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'response_data' => array_merge($response->data ?? [], [
                'is_additional_charge' => $isSurcharge,
                'order_id' => $order->id,
                'modification_intent_id' => $intentId,
            ]),
        ]);

        $leg = OrderPayment::create([
            'order_id' => $order->id,
            'payment_transaction_id' => $transaction->id,
            'modification_intent_id' => $intentId,
            'payment_method' => $paymentMethod,
            'amount' => $amount,
            'status' => OrderPayment::STATUS_PENDING,
            'sequence' => (int) ($opts['sequence'] ?? 0),
            'is_surcharge' => $isSurcharge,
            'meta' => $opts['meta'] ?? null,
        ]);

        $paymentFormHtml = null;
        if ($paymentParams && $gatewayObj && method_exists($gatewayObj, 'getPaymentFormHtml')) {
            try {
                /** @var mixed $gatewayObj */
                $paymentFormHtml = $gatewayObj->getPaymentFormHtml($paymentParams);
            } catch (\Exception $e) {
                // keep response usable even if form HTML generation fails
            }
        }

        return [
            'order_payment' => $leg,
            'transaction' => $transaction,
            'payment' => [
                'transaction_id' => $response->transactionId,
                'amount' => $amount,
                'currency' => $response->currency ?? 'SAR',
                'gateway' => $gateway,
                'payment_method' => $paymentMethod,
                'authorization_type' => $authorizationType,
                'payment_url' => $response->paymentUrl,
                'payment_params' => $paymentParams,
                'payment_form_html' => $paymentFormHtml,
                'redirect_instructions' => $response->redirectInstructions(),
                // moyasar.js embedded mode: config for the client-side form (null in
                // hosted-invoice / APS modes, where payment_url drives the redirect).
                'mode' => $response->data['mode'] ?? null,
                'moyasar' => $response->data['moyasar'] ?? null,
            ],
        ];
    }

    /**
     * Apply a staged total increase to the order once its surcharge is paid.
     * Idempotent: a resolved intent is left untouched.
     *
     * Re-reads the intent under a row lock so two concurrent surcharge-leg
     * callbacks can't both apply the same staged total (double-apply).
     */
    public function applyModificationIntent(OrderModificationIntent $intent, ?PaymentTransaction $transaction = null): void
    {
        $intent = OrderModificationIntent::whereKey($intent->getKey())->lockForUpdate()->first()
            ?? $intent;

        if ($intent->status === OrderModificationIntent::STATUS_RESOLVED) {
            return;
        }

        $order = $intent->order;
        if (! $order) {
            return;
        }

        $staged = $intent->staged_pricing ?? [];

        $this->applyStagedOrderModification($order, $staged);

        $intent->update([
            'status' => OrderModificationIntent::STATUS_RESOLVED,
            'payment_transaction_id' => $transaction?->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Commit staged order edit (items, addresses, pricing) after surcharge is paid.
     *
     * @param  array<string, mixed>  $staged
     */
    public function applyStagedOrderModification(Order $order, array $staged): void
    {
        if (! empty($staged['items']) && is_array($staged['items'])) {
            $this->replaceOrderItems($order, $staged['items']);
        }

        $pricingUpdate = array_filter([
            'total_amount' => $staged['total_amount'] ?? null,
            'discount_amount' => $staged['discount_amount'] ?? null,
            'tax_amount' => $staged['tax_amount'] ?? null,
            'delivery_fee' => $staged['delivery_fee'] ?? null,
            'final_amount' => $staged['final_amount'] ?? null,
            'distance' => $staged['distance'] ?? null,
        ], fn ($v) => $v !== null);

        $fieldUpdate = [];
        if (array_key_exists('pickup_at_vendor', $staged)) {
            $fieldUpdate['pickup_at_vendor'] = $staged['pickup_at_vendor'];
        }
        if (array_key_exists('delivery_at_vendor', $staged)) {
            $fieldUpdate['delivery_at_vendor'] = $staged['delivery_at_vendor'];
        }
        if (array_key_exists('pickup_address_id', $staged)) {
            $fieldUpdate['pickup_address_id'] = $staged['pickup_address_id'];
        }
        if (array_key_exists('delivery_address_id', $staged)) {
            $fieldUpdate['delivery_address_id'] = $staged['delivery_address_id'];
        }
        if (array_key_exists('notes', $staged)) {
            $fieldUpdate['notes'] = $staged['notes'];
        }
        if (array_key_exists('status', $staged)) {
            $fieldUpdate['status'] = $staged['status'];
        }

        $order->update(array_merge($pricingUpdate, $fieldUpdate));

        if (array_key_exists('coupon_discount_id', $staged)) {
            $order->update(['discount_id' => $staged['coupon_discount_id'] ?: null]);
        }

        if (! empty($staged['coupon_discount_id'])) {
            $discount = \Modules\Discount\Models\Discount::find($staged['coupon_discount_id']);
            if ($discount) {
                app(\Modules\Discount\Services\DiscountService::class)->incrementUsage($discount);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $itemsData
     */
    public function replaceOrderItems(Order $order, array $itemsData): void
    {
        foreach ($order->items as $oldItem) {
            $oldItem->additionalServicesPivot()->delete();
        }
        $order->items()->delete();

        // Match store: one DB row per unit, and one row per main service when
        // a cart line has multiple services (linked by line_group).
        foreach ($itemsData as $itemData) {
            $quantityToCreate = max(1, (int) ($itemData['quantity'] ?? 1));
            $serviceRows = $itemData['services'] ?? [[
                'service_id' => $itemData['service_id'],
                'service_piece_price' => $itemData['service_price'] ?? $itemData['service_piece_price'] ?? 0,
            ]];
            // Normalize keys used by updateOrder vs store.
            $serviceRows = array_map(function (array $row): array {
                return [
                    'service_id' => (int) ($row['service_id'] ?? $row['id'] ?? 0),
                    'service_piece_price' => (float) ($row['service_piece_price'] ?? $row['price'] ?? $row['service_price'] ?? 0),
                ];
            }, $serviceRows);

            $additionalServicesTotal = (float) ($itemData['additional_services_total'] ?? 0);
            if ($additionalServicesTotal <= 0 && ! empty($itemData['additional_services'])) {
                $additionalServicesTotal = (float) array_sum(array_column($itemData['additional_services'], 'price'));
            }

            for ($i = 0; $i < $quantityToCreate; $i++) {
                $lineGroup = count($serviceRows) > 1 ? (string) \Illuminate\Support\Str::uuid() : null;

                foreach ($serviceRows as $serviceIndex => $serviceRow) {
                    $isPrimary = $serviceIndex === 0;
                    $servicePrice = (float) $serviceRow['service_piece_price'];
                    $rowUnitPrice = $isPrimary
                        ? ($servicePrice + $additionalServicesTotal)
                        : $servicePrice;

                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'piece_id' => $itemData['piece_id'],
                        'service_id' => $serviceRow['service_id'],
                        'line_group' => $lineGroup,
                        'piece_price' => $itemData['piece_price'] ?? 0,
                        'service_price' => $servicePrice,
                        'quantity' => 1,
                        'unit_price' => $rowUnitPrice,
                        'total_price' => $rowUnitPrice,
                        'notes' => $itemData['note'] ?? $itemData['notes'] ?? null,
                        'images' => $itemData['images'] ?? $itemData['uploaded_image'] ?? null,
                    ]);

                    if ($isPrimary && ! empty($itemData['additional_services'])) {
                        foreach ($itemData['additional_services'] as $additionalService) {
                            \Modules\Order\Models\OrderItemAdditionalService::create([
                                'order_item_id' => $orderItem->id,
                                'service_addition_id' => $additionalService['id'],
                                'price' => $additionalService['price'],
                                'quantity' => 1,
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Expire a pending modification intent (superseded by a new edit).
     */
    public function expirePendingModificationIntents(int $orderId): void
    {
        OrderModificationIntent::where('order_id', $orderId)
            ->where('status', OrderModificationIntent::STATUS_PENDING)
            ->update(['status' => OrderModificationIntent::STATUS_EXPIRED]);
    }

    /**
     * Create a staged modification intent for a gateway surcharge.
     *
     * @param  array<string, mixed>  $stagedPricing
     */
    public function createModificationIntent(
        Order $order,
        string $delta,
        float $newTotal,
        float $newFinalAmount,
        array $stagedPricing
    ): OrderModificationIntent {
        $this->expirePendingModificationIntents($order->id);

        return OrderModificationIntent::create([
            'order_id' => $order->id,
            'delta_amount' => $delta,
            'new_total' => $newTotal,
            'new_final_amount' => $newFinalAmount,
            'staged_pricing' => $stagedPricing,
            'status' => OrderModificationIntent::STATUS_PENDING,
        ]);
    }

    /**
     * Apply staged total when all surcharge legs for the intent are paid.
     */
    public function applyModificationIntentIfFullyPaid(OrderModificationIntent $intent, ?PaymentTransaction $transaction = null): void
    {
        if ($this->surchargePaidTotal($intent->id) + 0.01 < (float) $intent->delta_amount) {
            return;
        }

        $this->applyModificationIntent($intent, $transaction);
    }

    /**
     * Sum of settled (paid) legs for an order.
     */
    public function paidTotal(Order $order): float
    {
        return (float) OrderPayment::where('order_id', $order->id)
            ->where('status', OrderPayment::STATUS_PAID)
            ->sum('amount');
    }

    /**
     * Whether the order's paid legs cover its final amount (1 cent tolerance).
     */
    public function isFullyPaid(Order $order): bool
    {
        return $this->paidTotal($order) + 0.01 >= (float) $order->final_amount;
    }

    /**
     * Checkout still awaiting a gateway leg (card/STC/etc.) — notifications must wait.
     */
    public function hasPendingCheckoutGatewayPayment(Order $order): bool
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('is_surcharge', false)
            ->where('status', OrderPayment::STATUS_PENDING)
            ->get()
            ->contains(fn (OrderPayment $leg) => ! $this->isWalletMethod($leg->payment_method)
                && $leg->payment_method !== PaymentMethod::CASH_ON_DELIVERY->value);
    }

    /**
     * Whether the order was created notifications should be sent now.
     */
    public function shouldSendOrderCreatedNotifications(Order $order): bool
    {
        return ! $this->hasPendingCheckoutGatewayPayment($order);
    }

    /**
     * Active holds on an order: gateway AUTHORIZATION holds and wallet reservations.
     */
    public function getOrderHolds(Order $order): array
    {
        $holds = [];
        $gatewayHeld = 0.0;
        $walletHeld = 0.0;

        $gatewayTransactions = PaymentTransaction::where('order_id', $order->id)
            ->where('status', 'authorized')
            ->where('gateway', '!=', 'wallet')
            ->get();

        foreach ($gatewayTransactions as $tx) {
            $amount = (float) ($tx->authorized_amount ?: $tx->amount);
            $gatewayHeld += $amount;
            $holds[] = [
                'type' => 'gateway',
                'hold_type' => 'authorization',
                'payment_method' => $tx->payment_method,
                'amount' => $amount,
                'status' => $tx->status,
                'transaction_id' => $tx->transaction_id,
            ];
        }

        $walletLegs = OrderPayment::with('paymentTransaction')
            ->where('order_id', $order->id)
            ->where('payment_method', PaymentMethod::Nathefah_WALLET->value)
            ->where('status', OrderPayment::STATUS_PENDING)
            ->get();

        foreach ($walletLegs as $leg) {
            $amount = (float) $leg->amount;
            $walletHeld += $amount;
            $holds[] = [
                'type' => 'wallet',
                'hold_type' => 'reservation',
                'payment_method' => $leg->payment_method,
                'amount' => $amount,
                'status' => $leg->status,
                'transaction_id' => $leg->paymentTransaction?->transaction_id,
            ];
        }

        $totalHeld = round($gatewayHeld + $walletHeld, 2);
        $paidTotal = round($this->paidTotal($order), 2);

        return [
            'is_holding' => $totalHeld > 0,
            'total_held' => $totalHeld,
            'gateway_held' => round($gatewayHeld, 2),
            'wallet_held' => round($walletHeld, 2),
            'amount_paid' => $paidTotal,
            'amount_remaining' => round(max(0.0, (float) $order->final_amount - $paidTotal), 2),
            'holds' => $holds,
        ];
    }

    /**
     * Client-facing payment status: completed | pending | failed | not_initiated.
     */
    public function resolveClientPaymentStatus(Order $order, ?PaymentTransaction $latest = null): string
    {
        if ($this->isFullyPaid($order) || $order->isPaid()) {
            return 'completed';
        }

        $paidTotal = $this->paidTotal($order);

        if ($this->getOrderHolds($order)['is_holding']) {
            return 'pending';
        }

        if (! $latest) {
            return ($order->payment_method ?? null) === PaymentMethod::CASH_ON_DELIVERY->value
                ? 'pending'
                : 'not_initiated';
        }

        return $this->normalizeGatewayPaymentStatus($latest->status, $paidTotal);
    }

    /**
     * Map a gateway transaction status to a simple client status.
     */
    public function normalizeGatewayPaymentStatus(?string $gatewayStatus, float $paidTotal = 0.0): string
    {
        if (in_array($gatewayStatus, ['refunded', 'partially_refunded'], true)) {
            return $paidTotal > 0 ? 'completed' : 'failed';
        }

        return match ($gatewayStatus) {
            'completed', 'authorized' => 'completed',
            'failed', 'cancelled', 'voided' => 'failed',
            'pending' => 'pending',
            'not_initiated' => 'not_initiated',
            default => 'pending',
        };
    }

    /**
     * Localized API message for a client payment status.
     */
    public function clientPaymentStatusMessage(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'completed' => __('order.payment_status_completed'),
            'failed' => __('order.payment_status_failed'),
            'pending' => __('order.payment_status_pending'),
            'not_initiated' => __('order.no_payment_initiated'),
            default => __('order.payment_status_pending'),
        };
    }

    /**
     * Refund a price decrease.
     *
     * - Gateway/card: try card refund first; if rejected → credit wallet and notify.
     * - Wallet: credit wallet.
     * - Cash (COD): never refund as money; only reduce amount to collect.
     *
     * @return float Amount credited/refunded immediately (wallet + captured gateway).
     */
    public function refundDecrease(Order $order, float $refundAmount, string $reason = 'Order update'): float
    {
        $remaining = round($refundAmount, 2);
        if ($remaining <= 0) {
            return 0.0;
        }

        $legs = OrderPayment::with('paymentTransaction')
            ->where('order_id', $order->id)
            ->where('status', OrderPayment::STATUS_PAID)
            ->where(function ($q) {
                $q->where('is_surcharge', false)
                    ->orWhereHas('modificationIntent', function ($q2) {
                        $q2->where('status', OrderModificationIntent::STATUS_RESOLVED);
                    });
            })
            ->orderByDesc('sequence')
            ->get();

        if ($legs->isEmpty()) {
            return $this->refundDecreaseViaTransactions($order, $remaining, $reason);
        }

        $walletLegs = $legs->filter(fn (OrderPayment $leg) => $this->isWalletMethod($leg->payment_method))->values();
        $gatewayLegs = $legs->filter(fn (OrderPayment $leg) => $this->isGatewayMethod($leg->payment_method))->values();
        $codLegs = $legs->filter(fn (OrderPayment $leg) => $this->isCodMethod($leg->payment_method))->values();

        $refundedNow = 0.0;
        $refundLines = [];

        // Prefer refunding card legs first (try gateway → wallet fallback).
        foreach ($gatewayLegs as $leg) {
            if ($remaining <= 0.005) {
                break;
            }
            $portion = min($remaining, $this->refundableAmountOnLeg($leg));
            if ($portion <= 0) {
                continue;
            }

            $tx = $leg->paymentTransaction;
            if (! $tx || ! in_array($tx->status, ['completed', 'authorized'], true)) {
                continue;
            }
            // Authorized: leave for capture/void on decrease unless cancelling — skip here
            // for price-edit decreases (capture listener handles hold).
            if ($tx->status === 'authorized') {
                continue;
            }

            $result = $this->refundGatewayOrWalletFallback($order, $tx, $portion, $reason);
            if ($result['amount'] > 0) {
                // Fallback path already marks the leg; gateway success needs markLegPartialRefund.
                if (($result['method'] ?? '') === 'gateway' && ! ($result['gateway_failed'] ?? false)) {
                    $this->markLegPartialRefund($leg, $result['amount']);
                }
                $remaining = round($remaining - $result['amount'], 2);
                $refundedNow += $result['amount'];
                $refundLines[] = $result;
            }
        }

        foreach ($walletLegs as $leg) {
            if ($remaining <= 0.005) {
                break;
            }
            $portion = min($remaining, $this->refundableAmountOnLeg($leg));
            if ($portion <= 0) {
                continue;
            }
            $this->creditWalletRefund($order, $portion, $reason, $leg);
            $remaining = round($remaining - $portion, 2);
            $refundedNow += $portion;
            $refundLines[] = [
                'amount' => $portion,
                'method' => 'wallet',
                'payment_method' => PaymentMethod::Nathefah_WALLET->value,
                'gateway_attempted' => false,
                'gateway_failed' => false,
                'gateway_failure_message' => null,
            ];
        }

        // Pure COD / leftover COD coverage: never refund cash; reduce amount to collect.
        $this->reduceCodLegsBy($codLegs, max($remaining, $refundAmount - $refundedNow));

        if ($refundLines !== []) {
            $this->notifyClientOfRefund($order, $refundLines, 'decrease', $reason);
        }

        return round($refundedNow, 2);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OrderPayment>  $codLegs
     */
    private function reduceCodLegsBy($codLegs, float $amount): void
    {
        $remaining = round($amount, 2);
        if ($remaining <= 0 || $codLegs->isEmpty()) {
            return;
        }

        foreach ($codLegs as $leg) {
            if ($remaining <= 0.005) {
                break;
            }
            $portion = min($remaining, $this->refundableAmountOnLeg($leg));
            if ($portion <= 0) {
                continue;
            }
            $this->reduceCodLegAmount($leg, $portion);
            $remaining = round($remaining - $portion, 2);
        }
    }

    /**
     * Credit the client wallet without requiring a specific OrderPayment leg.
     */
    private function creditClientWallet(Order $order, float $amount, string $reason): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $clientId = (int) $order->client_id;
        DB::table('clients')->where('id', $clientId)->lockForUpdate()->first();
        DB::table('clients')->where('id', $clientId)->increment('wallet_balance', $amount);

        DB::table('wallet_transactions')->insert([
            'client_id' => $clientId,
            'type' => 'credit',
            'amount' => $amount,
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'description' => 'Order #'.$order->order_number.' - '.$reason,
            'order_id' => $order->id,
            'transaction_id' => 'WALLET-REFUND-'.$order->id.'-'.uniqid(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Full cancellation refund: try card/gateway first when possible; if the gateway
     * rejects the refund, credit the client wallet instead. Cash is never refunded as money.
     * Sends a client notification with amount, order, refund channel, and any card failure.
     */
    public function refundOrderOnCancellation(Order $order, ?string $reason = null): void
    {
        $reason = $reason ?? $order->cancelled_reason ?? 'Order cancelled';
        $order = $order->fresh() ?? $order;

        $refundLines = $this->releaseOrderWalletReservations($order->id);

        $transactions = PaymentTransaction::where('order_id', $order->id)
            ->whereIn('status', ['authorized', 'completed', 'pending'])
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            if ($transaction->gateway === 'wallet') {
                continue;
            }

            if ($transaction->status === 'authorized' && $transaction->fort_id) {
                $authorized = (float) ($transaction->authorized_amount ?: $transaction->amount);
                $result = $this->refundGatewayOrWalletFallback($order, $transaction, $authorized, $reason);
                if ($result['amount'] > 0) {
                    $this->markTransactionLegRefunded($transaction, $result);
                    $refundLines[] = $result;
                }

                continue;
            }

            if ($transaction->status === 'completed' && $transaction->fort_id) {
                $refundable = round((float) $transaction->amount - (float) $transaction->refund_amount, 2);
                if ($refundable > 0) {
                    $result = $this->refundGatewayOrWalletFallback($order, $transaction, $refundable, $reason);
                    if ($result['amount'] > 0) {
                        $this->markTransactionLegRefunded($transaction, $result);
                        $refundLines[] = $result;
                    }
                }
            }
        }

        $walletLegs = OrderPayment::with('paymentTransaction')
            ->where('order_id', $order->id)
            ->where('payment_method', PaymentMethod::Nathefah_WALLET->value)
            ->where('status', OrderPayment::STATUS_PAID)
            ->get();

        foreach ($walletLegs as $leg) {
            $refundable = $this->refundableAmountOnLeg($leg);
            if ($refundable > 0) {
                $this->creditWalletRefund($order, $refundable, $reason, $leg);
                $refundLines[] = [
                    'amount' => $refundable,
                    'method' => 'wallet',
                    'payment_method' => PaymentMethod::Nathefah_WALLET->value,
                    'gateway_attempted' => false,
                    'gateway_failed' => false,
                    'gateway_failure_message' => null,
                ];
            }
        }

        $coveredTxIds = OrderPayment::where('order_id', $order->id)
            ->where('payment_method', PaymentMethod::Nathefah_WALLET->value)
            ->whereNotNull('payment_transaction_id')
            ->pluck('payment_transaction_id');

        $legacyWalletTxs = PaymentTransaction::where('order_id', $order->id)
            ->where('gateway', 'wallet')
            ->where('status', 'completed')
            ->when($coveredTxIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $coveredTxIds))
            ->get();

        foreach ($legacyWalletTxs as $tx) {
            $refundable = round((float) $tx->amount - (float) $tx->refund_amount, 2);
            if ($refundable <= 0) {
                continue;
            }

            $this->creditClientWallet($order, $refundable, $reason);
            $tx->update([
                'refund_amount' => (float) $tx->amount,
                'status' => 'refunded',
                'refunded_at' => now(),
            ]);
            $refundLines[] = [
                'amount' => $refundable,
                'method' => 'wallet',
                'payment_method' => PaymentMethod::Nathefah_WALLET->value,
                'gateway_attempted' => false,
                'gateway_failed' => false,
                'gateway_failure_message' => null,
            ];
        }

        OrderPayment::where('order_id', $order->id)
            ->where('payment_method', PaymentMethod::CASH_ON_DELIVERY->value)
            ->where('status', OrderPayment::STATUS_PAID)
            ->update(['status' => OrderPayment::STATUS_CANCELLED]);

        OrderPayment::where('order_id', $order->id)
            ->where('status', OrderPayment::STATUS_PENDING)
            ->update(['status' => OrderPayment::STATUS_CANCELLED]);

        $order->update(['amount_remaining' => 0]);

        $this->notifyClientOfRefund($order, $refundLines, 'cancellation', $reason);
    }

    /**
     * Attempt gateway refund/void first. If the gateway rejects (or fails), credit the
     * client wallet for the same amount and mark the transaction as wallet-routed.
     *
     * @return array{
     *   amount: float,
     *   method: 'gateway'|'wallet'|'void'|'none',
     *   payment_method: ?string,
     *   gateway_attempted: bool,
     *   gateway_failed: bool,
     *   gateway_failure_message: ?string
     * }
     */
    public function refundGatewayOrWalletFallback(
        Order $order,
        PaymentTransaction $transaction,
        float $amount,
        string $reason
    ): array {
        $amount = round($amount, 2);
        $none = [
            'amount' => 0.0,
            'method' => 'none',
            'payment_method' => $transaction->payment_method,
            'gateway_attempted' => false,
            'gateway_failed' => false,
            'gateway_failure_message' => null,
        ];

        if ($amount <= 0 || $transaction->gateway === 'wallet') {
            return $none;
        }

        $gatewayResult = $this->attemptGatewayRefund($transaction, $amount, $reason);

        if ($gatewayResult['amount'] > 0) {
            return [
                'amount' => $gatewayResult['amount'],
                'method' => $gatewayResult['method'],
                'payment_method' => $transaction->payment_method,
                'gateway_attempted' => true,
                'gateway_failed' => false,
                'gateway_failure_message' => null,
            ];
        }

        // Gateway rejected / failed — fall back to wallet.
        $failureMessage = $gatewayResult['failure_message']
            ?? __('order.refund_card_rejected');

        $this->creditClientWallet($order, $amount, $reason.' (card refund failed — wallet fallback)');

        $tx = $transaction->fresh();
        if ($tx) {
            $newRefundTotal = round((float) $tx->refund_amount + $amount, 2);
            $tx->update([
                'refund_amount' => min($newRefundTotal, (float) $tx->amount),
                'status' => $newRefundTotal >= (float) $tx->amount ? 'refunded' : 'partially_refunded',
                'refunded_at' => now(),
                'response_data' => array_merge($tx->response_data ?? [], [
                    'wallet_routed_refund' => true,
                    'wallet_routed_reason' => $reason,
                    'gateway_refund_failed' => true,
                    'gateway_failure_message' => $failureMessage,
                ]),
            ]);
        }

        // Keep OrderPayment ledger in sync when a leg points at this transaction.
        $leg = OrderPayment::where('payment_transaction_id', $transaction->id)->first();
        if ($leg) {
            $this->markLegPartialRefund($leg, $amount);
        }

        return [
            'amount' => $amount,
            'method' => 'wallet',
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'gateway_attempted' => true,
            'gateway_failed' => true,
            'gateway_failure_message' => $failureMessage,
        ];
    }

    /**
     * @return array{amount: float, method: 'gateway'|'void'|'none', failure_message: ?string}
     */
    private function attemptGatewayRefund(PaymentTransaction $transaction, float $amount, string $reason): array
    {
        $amount = round($amount, 2);
        $fail = fn (?string $msg) => [
            'amount' => 0.0,
            'method' => 'none',
            'failure_message' => $msg,
        ];

        try {
            return (array) DB::transaction(function () use ($transaction, $amount, $reason, $fail) {
                $locked = PaymentTransaction::whereKey($transaction->id)
                    ->whereIn('status', ['authorized', 'completed'])
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->gateway === 'wallet') {
                    return $fail(__('order.refund_card_rejected'));
                }

                $gatewayName = strtolower(str_replace(' ', '_', $locked->gateway));
                $this->paymentService->setGateway($gatewayName);

                if ($locked->status === 'authorized') {
                    if (! $locked->fort_id) {
                        return $fail(__('order.refund_card_rejected'));
                    }

                    $voidAmount = min($amount, (float) ($locked->authorized_amount ?: $locked->amount));
                    $response = $this->paymentService->voidAuthorization(
                        $locked->fort_id,
                        $locked->transaction_id,
                        $locked->payfortPaymentOption()
                    );

                    if ($response->isSuccessful()) {
                        $locked->update([
                            'status' => 'cancelled',
                            'response_data' => array_merge($locked->response_data ?? [], $response->data ?? []),
                        ]);

                        return [
                            'amount' => round($voidAmount, 2),
                            'method' => 'void',
                            'failure_message' => null,
                        ];
                    }

                    return $fail($response->message ?? __('order.refund_card_rejected'));
                }

                if (! $locked->fort_id) {
                    return $fail(__('order.refund_card_rejected'));
                }

                $alreadyRefunded = (float) $locked->refund_amount;
                $refundable = round((float) $locked->amount - $alreadyRefunded, 2);
                $refundAmount = min($amount, $refundable);

                if ($refundAmount <= 0) {
                    return $fail(null);
                }

                $response = $this->paymentService->refund(new RefundRequest(
                    transactionId: $locked->transaction_id,
                    amount: $refundAmount,
                    reason: $reason,
                    paymentOption: $locked->payfortPaymentOption(),
                    gatewayPaymentId: $locked->fort_id
                ));

                if (! $response->isSuccessful()) {
                    return $fail($response->message ?? __('order.refund_card_rejected'));
                }

                $refundStatus = in_array($response->status, ['pending', 'completed', 'failed'], true)
                    ? $response->status
                    : 'completed';

                PaymentRefund::create([
                    'payment_transaction_id' => $locked->id,
                    'refund_id' => $response->refundId,
                    'amount' => $refundAmount,
                    'currency' => $locked->currency,
                    'status' => $refundStatus,
                    'reason' => $reason,
                    'response_data' => $response->data,
                    'processed_at' => now(),
                ]);

                $newRefundTotal = round($alreadyRefunded + $refundAmount, 2);
                $locked->update([
                    'refund_amount' => $newRefundTotal,
                    'status' => $newRefundTotal >= (float) $locked->amount ? 'refunded' : 'partially_refunded',
                    'refunded_at' => now(),
                ]);

                return [
                    'amount' => $refundAmount,
                    'method' => 'gateway',
                    'failure_message' => null,
                ];
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Gateway refund attempt failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return $fail($e->getMessage() ?: __('order.refund_card_rejected'));
        }
    }

    /**
     * Refund (or void) a gateway transaction up to $amount (no wallet fallback).
     *
     * @return float Amount actually refunded/voided.
     */
    public function refundGatewayTransaction(PaymentTransaction $transaction, float $amount, string $reason): float
    {
        $result = $this->attemptGatewayRefund($transaction, $amount, $reason);

        return (float) ($result['amount'] ?? 0);
    }

    /**
     * Notify the client about how their money was refunded.
     *
     * @param  list<array{amount: float, method: string, payment_method: ?string, gateway_attempted: bool, gateway_failed: bool, gateway_failure_message: ?string}>  $lines
     */
    public function notifyClientOfRefund(
        Order $order,
        array $lines,
        string $context = 'cancellation',
        ?string $reason = null
    ): void {
        $lines = array_values(array_filter($lines, fn ($l) => ($l['amount'] ?? 0) > 0.005));
        if ($lines === []) {
            return;
        }

        $total = round(array_sum(array_column($lines, 'amount')), 2);
        $orderNumber = $order->order_number;
        $hadCardFailure = collect($lines)->contains(fn ($l) => ($l['gateway_failed'] ?? false) === true);
        $methods = collect($lines)->map(function ($l) {
            $method = $l['method'] ?? 'wallet';

            return match ($method) {
                'gateway', 'void' => $l['payment_method'] ?? 'card',
                'wallet' => PaymentMethod::Nathefah_WALLET->value,
                default => (string) $method,
            };
        })->unique()->values()->all();

        $methodLabelsAr = [
            'visa' => 'فيزا',
            'mastercard' => 'ماستركارد',
            'mada' => 'مدى',
            'credit_card' => 'دفع الكتروني',
            'nazefah_wallet' => 'المحفظة',
            'wallet' => 'المحفظة',
            'card' => 'دفع الكتروني',
            'cash_on_delivery' => 'الدفع عند الاستلام',
        ];
        $methodLabelsEn = [
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'mada' => 'Mada',
            'nazefah_wallet' => 'wallet',
            'wallet' => 'wallet',
            'card' => 'card',
            'cash_on_delivery' => 'cash on delivery',
        ];

        $methodsAr = implode('، ', array_map(
            fn ($m) => $methodLabelsAr[$m] ?? $m,
            $methods
        ));
        $methodsEn = implode(', ', array_map(
            fn ($m) => $methodLabelsEn[$m] ?? $m,
            $methods
        ));

        $titleAr = $context === 'cancellation'
            ? 'تم استرداد مبلغ طلبك'
            : 'تم تعديل مبلغ طلبك واسترداد الفرق';
        $titleEn = $context === 'cancellation'
            ? 'Your order refund has been processed'
            : 'Order price decrease refunded';

        $bodyAr = "تم استرداد مبلغ {$total} ر.س للطلب #{$orderNumber} عبر: {$methodsAr}.";
        $bodyEn = "An amount of {$total} SAR was refunded for order #{$orderNumber} via: {$methodsEn}.";

        if ($hadCardFailure) {
            $failDetail = collect($lines)
                ->filter(fn ($l) => ($l['gateway_failed'] ?? false) === true)
                ->map(fn ($l) => (string) ($l['gateway_failure_message'] ?? ''))
                ->filter()
                ->first();

            $bodyAr .= ' تمت محاولة إرجاع المبلغ إلى البطاقة لكن العملية رُفضت، لذلك تم إيداع المبلغ في محفظتك.';
            $bodyEn .= ' A card refund was attempted but rejected, so the amount was credited to your wallet.';
            if ($failDetail) {
                $bodyAr .= ' تفاصيل الرفض: '.$failDetail;
                $bodyEn .= ' Rejection detail: '.$failDetail;
            }
        }

        try {
            app(\App\Services\OrderNotificationService::class)->sendToClient(
                $order,
                $titleAr,
                $titleEn,
                $bodyAr,
                $bodyEn,
                'order_refund',
                [
                    'order_id' => (string) $order->id,
                    'order_number' => $orderNumber,
                    'refund_total' => (string) $total,
                    'refund_methods' => $methods,
                    'refund_context' => $context,
                    'gateway_failed' => $hadCardFailure ? '1' : '0',
                    'reason' => $reason,
                    'refund_lines' => $lines,
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send refund notification', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Legacy orders without order_payments rows.
     * Same channel rule: try original method first, wallet on gateway failure.
     */
    private function refundDecreaseViaTransactions(Order $order, float $remaining, string $reason): float
    {
        $refundedNow = 0.0;
        $refundLines = [];

        $transactions = PaymentTransaction::where('order_id', $order->id)
            ->where('is_additional_charge', false)
            ->whereIn('status', ['completed'])
            ->orderByDesc('id')
            ->get();

        $walletTxs = $transactions->filter(fn ($tx) => $tx->gateway === 'wallet')->values();
        $gatewayTxs = $transactions->filter(fn ($tx) => $tx->gateway !== 'wallet')->values();

        foreach ($gatewayTxs as $tx) {
            if ($remaining <= 0.005) {
                break;
            }

            $refundable = round((float) $tx->amount - (float) $tx->refund_amount, 2);
            $portion = min($remaining, $refundable);
            if ($portion <= 0 || ! $tx->fort_id) {
                continue;
            }

            $result = $this->refundGatewayOrWalletFallback($order, $tx, $portion, $reason);
            if ($result['amount'] > 0) {
                $remaining = round($remaining - $result['amount'], 2);
                $refundedNow += $result['amount'];
                $refundLines[] = $result;
            }
        }

        foreach ($walletTxs as $tx) {
            if ($remaining <= 0.005) {
                break;
            }

            $refundable = round((float) $tx->amount - (float) $tx->refund_amount, 2);
            $portion = min($remaining, $refundable);
            if ($portion <= 0) {
                continue;
            }

            $this->creditClientWallet($order, $portion, $reason);
            $newRefundTotal = round((float) $tx->refund_amount + $portion, 2);
            $tx->update([
                'refund_amount' => $newRefundTotal,
                'status' => $newRefundTotal >= (float) $tx->amount ? 'refunded' : 'partially_refunded',
                'refunded_at' => now(),
            ]);
            $remaining = round($remaining - $portion, 2);
            $refundedNow += $portion;
            $refundLines[] = [
                'amount' => $portion,
                'method' => 'wallet',
                'payment_method' => PaymentMethod::Nathefah_WALLET->value,
                'gateway_attempted' => false,
                'gateway_failed' => false,
                'gateway_failure_message' => null,
            ];
        }

        if ($refundLines !== []) {
            $this->notifyClientOfRefund($order, $refundLines, 'decrease', $reason);
        }

        return round($refundedNow, 2);
    }

    private function refundableAmountOnLeg(OrderPayment $leg): float
    {
        $alreadyRefunded = (float) ($leg->meta['refunded_amount'] ?? 0);

        return round(max(0.0, (float) $leg->amount - $alreadyRefunded), 2);
    }

    private function markLegPartialRefund(OrderPayment $leg, float $amount): void
    {
        $amount = round($amount, 2);
        $meta = $leg->meta ?? [];
        $refunded = round((float) ($meta['refunded_amount'] ?? 0) + $amount, 2);
        $meta['refunded_amount'] = $refunded;

        $updates = ['meta' => $meta];
        if ($refunded + 0.01 >= (float) $leg->amount) {
            $updates['status'] = OrderPayment::STATUS_REFUNDED;
        }

        $leg->update($updates);
    }

    /**
     * Keep the OrderPayment leg ledger in sync after a successful (non-wallet-fallback)
     * gateway refund on a PaymentTransaction. Without this, a card leg stays 'paid' even
     * though its money was refunded via the gateway, unlike wallet/COD legs which are
     * updated inline — see refundOrderOnCancellation().
     */
    private function markTransactionLegRefunded(PaymentTransaction $transaction, array $result): void
    {
        if (($result['amount'] ?? 0) <= 0) {
            return;
        }
        if (($result['method'] ?? '') !== 'gateway' || ($result['gateway_failed'] ?? false)) {
            return;
        }

        $leg = OrderPayment::where('payment_transaction_id', $transaction->id)->first();
        if ($leg) {
            $this->markLegPartialRefund($leg, (float) $result['amount']);
        }
    }

    private function reduceCodLegAmount(OrderPayment $leg, float $amount): void
    {
        $amount = round($amount, 2);
        $newAmount = round(max(0.0, (float) $leg->amount - $amount), 2);
        $leg->update(['amount' => $newAmount]);
    }

    private function creditWalletRefund(Order $order, float $amount, string $reason, OrderPayment $leg): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $clientId = (int) $order->client_id;

        DB::table('clients')->where('id', $clientId)->lockForUpdate()->first();
        DB::table('clients')->where('id', $clientId)->increment('wallet_balance', $amount);

        $reference = 'WALLET-REFUND-'.$order->id.'-'.$leg->id.'-'.uniqid();

        DB::table('wallet_transactions')->insert([
            'client_id' => $clientId,
            'type' => 'credit',
            'amount' => $amount,
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'description' => 'Order #'.$order->order_number.' - '.$reason,
            'order_id' => $order->id,
            'transaction_id' => $reference,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->markLegPartialRefund($leg, $amount);

        if ($leg->paymentTransaction) {
            $tx = $leg->paymentTransaction->fresh();
            $newRefundTotal = round((float) $tx->refund_amount + $amount, 2);
            $tx->update([
                'refund_amount' => $newRefundTotal,
                'status' => $newRefundTotal >= (float) $tx->amount ? 'refunded' : 'partially_refunded',
                'refunded_at' => now(),
            ]);
        }
    }

    /**
     * Settle split legs for a pending order during checkout.
     */
    public function settleSplitLegsForPendingOrder(\Modules\Order\Models\PendingOrder $pendingOrder, array $legs, $client, array $opts = []): array
    {
        $isSurcharge = false;

        $walletNeeded = 0.0;
        foreach ($legs as $leg) {
            if ($this->isWalletMethod($leg['payment_method'])) {
                $walletNeeded += $leg['amount'];
            }
        }
        if ($walletNeeded > 0) {
            $available = $this->availableWalletBalance((int) $client->id, lock: true);
            if ($available + 0.01 < $walletNeeded) {
                throw new InsufficientWalletBalanceException(round($walletNeeded, 2), round($available, 2));
            }
        }

        $gatewayPayments = [];
        $walletPayments = [];
        $sequence = 0;
        $reserveWalletUntilGatewayPays = $this->splitHasGatewayLeg($legs);

        foreach ($legs as $leg) {
            $method = $leg['payment_method'];
            $amount = round((float) $leg['amount'], 2);

            $legOpts = [
                'is_surcharge' => $isSurcharge,
                'sequence' => $sequence++,
                'reference_prefix' => 'LEG',
                'meta' => $opts['meta'] ?? null,
            ];

            if ($this->isWalletMethod($method)) {
                if ($reserveWalletUntilGatewayPays) {
                    $walletTx = $this->reserveWalletLegForPendingOrder($pendingOrder, $amount, $client, $legOpts);
                    $walletPayments[] = [
                        'transaction_id' => $walletTx->transaction_id,
                        'amount' => $amount,
                        'currency' => 'SAR',
                        'gateway' => 'wallet',
                        'environment' => config('app.env') === 'production' ? 'production' : 'test',
                        'payment_method' => $method,
                        'status' => 'pending',
                        // No hold placed — nothing is deducted or reserved yet; the
                        // wallet is debited only after the gateway leg is paid.
                        'is_held' => false,
                        'charged_after_gateway' => true,
                    ];
                } else {
                    throw new \LogicException('Wallet-only payment should not use PendingOrder path.');
                }

                continue;
            }

            if ($method === PaymentMethod::CASH_ON_DELIVERY->value) {
                throw new \LogicException('COD payment should not use PendingOrder path.');
            }

            $result = $this->createGatewayLegForPendingOrder($pendingOrder, $amount, $method, $client, $legOpts);
            if ($result === null) {
                throw new \RuntimeException('order.payment_init_failed');
            }
            $gatewayPayments[] = $result['payment'];
        }

        $walletAmount = round(array_sum(array_column($walletPayments, 'amount')), 2);
        $gatewayAmount = round(array_sum(array_map(fn ($p) => (float) ($p['amount'] ?? 0), $gatewayPayments)), 2);

        return [
            'gateway_payments' => $gatewayPayments,
            'wallet_payments' => $walletPayments,
            'summary' => [
                'total_due' => round($walletAmount + $gatewayAmount, 2),
                'wallet_amount' => $walletAmount,
                'gateway_amount' => $gatewayAmount,
                'wallet_held' => false,
                'wallet_charged_after_gateway' => $walletAmount > 0,
                'gateway_pending' => $gatewayAmount > 0,
            ],
            'paid_total' => 0.0,
            'fully_paid' => false,
        ];
    }

    /**
     * Create a gateway payment leg for a pending order.
     */
    public function createGatewayLegForPendingOrder(\Modules\Order\Models\PendingOrder $pendingOrder, float $amount, string $paymentMethod, $client, array $opts = []): ?array
    {
        $amount = round((float) $amount, 2);
        $methodEnum = PaymentMethod::tryFrom($paymentMethod);

        if (! $methodEnum || ! $methodEnum->requiresPayfort()) {
            return null;
        }

        $gateway = PaymentMethod::getGatewayName($paymentMethod);
        $paymentOption = $methodEnum->getPayfortPaymentOption();

        $this->paymentService->setGateway($gateway);

        $orderNumber = $pendingOrder->order_data['order_number'];
        $prefix = $opts['reference_prefix'] ?? 'LEG';
        $reference = $orderNumber.'-'.$prefix.'-'.($opts['sequence'] ?? 0).'-'.uniqid();

        $customerEmail = $client->email ?? config('payment.default_customer_email', 'noreply@nathefah.com');
        $customerName = $client->full_name ?? $client->name ?? 'Customer';
        $customerPhone = $client->phone ?? null;

        $returnUrl = $this->paymentRouteUrl('payment.callback', '/api/v1/payments/callback').'?pending_order_id='.$pendingOrder->id;
        $cancelUrl = $this->paymentRouteUrl('payment.cancel', '/api/v1/payments/cancel').'?pending_order_id='.$pendingOrder->id;

        $paymentRequest = new PaymentRequest(
            amount: $amount,
            currency: 'SAR',
            orderId: $reference,
            customerEmail: $customerEmail,
            customerName: $customerName,
            customerPhone: $customerPhone,
            returnUrl: $returnUrl,
            cancelUrl: $cancelUrl,
            paymentOption: $paymentOption,
            metadata: [
                'pending_order_id' => $pendingOrder->id,
                'order_number' => $orderNumber,
                'client_id' => $pendingOrder->client_id,
                'payment_option' => $paymentOption,
                'is_additional_charge' => false,
            ],
        );

        $response = $this->paymentService->initializePayment($paymentRequest);

        if (! $response->isSuccessful()) {
            return null;
        }

        $paymentParams = $response->data['payment_params'] ?? null;
        if (
            $paymentMethod === PaymentMethod::STC_PAY->value
            && $paymentParams !== null
            && (($paymentParams['payment_option'] ?? null) !== 'STCPAY')
        ) {
            return null;
        }

        $gatewayObj = $this->paymentService->getActiveGateway();

        $transaction = PaymentTransaction::create([
            'order_id' => null,
            'gateway' => $gatewayObj->getName(),
            'transaction_id' => $response->transactionId,
            'amount' => $amount,
            'currency' => $response->currency ?? 'SAR',
            'status' => $response->status ?? 'pending',
            'payment_method' => $paymentMethod,
            'is_additional_charge' => false,
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'response_data' => array_merge($response->data ?? [], [
                'is_additional_charge' => false,
                'pending_order_id' => $pendingOrder->id,
                'payment_method' => $paymentMethod,
            ]),
        ]);

        $paymentFormHtml = null;
        if ($paymentParams && $gatewayObj && method_exists($gatewayObj, 'getPaymentFormHtml')) {
            try {
                $paymentFormHtml = $gatewayObj->getPaymentFormHtml($paymentParams);
            } catch (\Exception $e) {}
        }

        return [
            'transaction' => $transaction,
            'payment' => [
                'transaction_id' => $response->transactionId,
                'amount' => $amount,
                'currency' => $response->currency ?? 'SAR',
                'gateway' => $gateway,
                'environment' => $response->data['environment'] ?? null,
                'payment_method' => $paymentMethod,
                'payment_url' => $response->paymentUrl,
                'payment_params' => $paymentParams,
                'payment_form_html' => $paymentFormHtml,
                'redirect_instructions' => $response->redirectInstructions(),
                // moyasar.js embedded mode: config for the client-side form (null in
                // hosted-invoice / APS modes, where payment_url drives the redirect).
                'mode' => $response->data['mode'] ?? null,
                'moyasar' => $response->data['moyasar'] ?? null,
            ],
        ];
    }

    /**
     * Record the wallet portion of a split checkout WITHOUT holding any funds.
     *
     * The wallet is intentionally left untouched here: no wallet_hold_amount is
     * placed, so the customer's spendable balance does not drop while they complete
     * the card payment. The actual debit happens later, in the card payment's
     * webhook — {@see captureReservedWalletLeg()} re-checks the balance and debits
     * all-or-nothing once the order is materialized. The pre-check below only rejects
     * an obviously-underfunded checkout early (it reads the balance, it does not hold).
     */
    public function reserveWalletLegForPendingOrder(\Modules\Order\Models\PendingOrder $pendingOrder, float $amount, $client, array $opts = [])
    {
        $amount = round((float) $amount, 2);
        $available = $this->availableWalletBalance((int) $client->id, lock: true);

        if ($available + 0.01 < $amount) {
            throw new InsufficientWalletBalanceException($amount, $available);
        }

        $orderNumber = $pendingOrder->order_data['order_number'];
        $reference = 'WALLET-PENDING-'.$pendingOrder->id.'-'.($opts['sequence'] ?? 0).'-'.uniqid();

        DB::table('wallet_transactions')->insert([
            'client_id' => $client->id,
            'type' => 'debit',
            'amount' => $amount,
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'description' => 'Order #'.$orderNumber.' - payment (wallet, awaiting card payment)',
            'order_id' => null,
            'transaction_id' => $reference,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PaymentTransaction::create([
            'order_id' => null,
            'gateway' => 'wallet',
            'transaction_id' => $reference,
            'amount' => $amount,
            'currency' => 'SAR',
            'status' => 'pending',
            'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            'is_additional_charge' => false,
            'customer_email' => $client->email ?? null,
            'customer_name' => $client->full_name ?? $client->name ?? null,
            'customer_phone' => $client->phone ?? null,
            'response_data' => [
                'pending_order_id' => $pendingOrder->id,
                'client_id' => $client->id,
                'is_additional_charge' => false,
                'wallet_reserved' => true,
                // No wallet_hold_amount was placed — the debit is deferred to the
                // webhook. captureReservedWalletLeg()/release read this flag so they
                // never touch (or wrongly release) the global hold counter.
                'wallet_hold_placed' => false,
                'payment_method' => PaymentMethod::Nathefah_WALLET->value,
            ],
        ]);
    }

    /**
     * Release wallet reservations for a pending order that was cancelled or expired.
     *
     * Falls back to the pending_orders table to resolve client_id for records that
     * were created before the client_id fix (response_data may not contain it).
     */
    public function releasePendingOrderWalletReservations(int $pendingOrderId): void
    {
        $transactions = PaymentTransaction::whereNull('order_id')
            ->where(function ($q) use ($pendingOrderId) {
                $q->where('response_data->pending_order_id', $pendingOrderId)
                  ->orWhere('response_data->pending_order_id', (string) $pendingOrderId);
            })
            ->where('gateway', 'wallet')
            ->where('status', 'pending')
            ->get();

        // Resolve client_id once for fallback (old records missing it in response_data)
        $fallbackClientId = null;

        foreach ($transactions as $transaction) {
            $clientId = (int) ($transaction->response_data['client_id'] ?? 0);

            if (! $clientId) {
                // Lazy-load once from pending_orders (supports soft-deleted records too)
                if ($fallbackClientId === null) {
                    $fallbackClientId = (int) (\Modules\Order\Models\PendingOrder::withTrashed()
                        ->find($pendingOrderId)?->client_id ?? 0);
                }
                $clientId = $fallbackClientId;
            }

            if (! $clientId) {
                \Illuminate\Support\Facades\Log::warning('releasePendingOrderWalletReservations: could not resolve client_id', [
                    'pending_order_id'  => $pendingOrderId,
                    'payment_tx_id'     => $transaction->id,
                    'transaction_id'    => $transaction->transaction_id,
                ]);

                continue;
            }

            $amount = round((float) $transaction->amount, 2);

            // Only release a hold if one was actually placed. Deferred (no-hold)
            // checkout legs never incremented wallet_hold_amount, so decrementing it
            // here would wrongly release another order's hold from the shared counter.
            $holdPlaced = (bool) ($transaction->response_data['wallet_hold_placed'] ?? true);

            DB::table('clients')->where('id', $clientId)->lockForUpdate()->first();

            if ($holdPlaced) {
                $hold = (float) (DB::table('clients')->where('id', $clientId)->value('wallet_hold_amount') ?? 0);
                $releaseHold = min($amount, $hold);

                if ($releaseHold > 0) {
                    DB::table('clients')->where('id', $clientId)->decrement('wallet_hold_amount', $releaseHold);
                }
            }

            $transaction->update(['status' => 'cancelled']);

            DB::table('wallet_transactions')
                ->where('transaction_id', $transaction->transaction_id)
                ->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }
}

