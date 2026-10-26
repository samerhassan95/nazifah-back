<?php

namespace Modules\Payment\Services;

use App\Enums\PaymentMethod;
use Modules\Client\Models\ClientCard;
use Modules\Payment\Models\PaymentTransaction;

class ClientCardService
{
    /**
     * Persist or refresh a PayFort-tokenized card for the client after a successful payment.
     */
    public function upsertFromPaymentTransaction(
        PaymentTransaction $transaction,
        ?string $tokenName,
        array $payfortData = []
    ): ?ClientCard {
        if (! $tokenName) {
            return null;
        }

        $clientId = $this->resolveClientId($transaction);
        if (! $clientId) {
            return null;
        }

        $cardBrand = $this->resolveCardBrand($transaction, $payfortData);
        if (! $cardBrand || ! PaymentMethod::tryFrom($cardBrand)?->supportsPayfortTokenization()) {
            return null;
        }

        $cardNumber = $payfortData['card_number']
            ?? $transaction->response_data['card_number']
            ?? null;
        $lastFour = $this->extractLastFour($cardNumber);

        if (! $lastFour) {
            return null;
        }

        $card = ClientCard::query()->firstOrNew([
            'payfort_token_name' => $tokenName,
        ]);

        $isNew = ! $card->exists;

        $card->fill([
            'client_id' => $clientId,
            'card_brand' => $cardBrand,
            'card_holder_name' => $payfortData['card_holder_name']
                ?? $transaction->response_data['card_holder_name']
                ?? $transaction->customer_name,
            'last_four' => $lastFour,
            'expiry_date' => $payfortData['expiry_date']
                ?? $transaction->response_data['expiry_date']
                ?? null,
            'source_payment_transaction_id' => $transaction->id,
            'last_used_at' => now(),
        ]);

        if ($isNew && ! ClientCard::where('client_id', $clientId)->where('is_default', true)->exists()) {
            $card->is_default = true;
        }

        $card->save();

        return $card;
    }

    /**
     * Persist a Moyasar-tokenized card. Moyasar's checkout page always requests a
     * token now (credit_card.save_card is forced true at Moyasar.init() time, since
     * that option can't be toggled after init without a disruptive form re-render —
     * see moyasar_checkout.blade.php), so a token's presence is no longer itself the
     * opt-in signal. The actual payer choice is recorded separately, by the checkout
     * page's own JS calling PaymentController::saveCardPreference() right before
     * submitting to Moyasar, and read here from the transaction. Default to NOT
     * saving if that call never landed (e.g. a JS error) — never persist a card
     * without a confirmed opt-in. CVC is never stored — Moyasar tokens don't carry it.
     */
    public function upsertFromMoyasarSource(PaymentTransaction $transaction, array $source): ?ClientCard
    {
        if (! (bool) ($transaction->response_data['save_card_intent'] ?? false)) {
            return null;
        }

        $token = $source['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        $clientId = $this->resolveClientId($transaction);
        if (! $clientId) {
            return null;
        }

        // Confirmed against live transactions: Moyasar's source.company sends
        // "master", not "mastercard", for Mastercard-branded cards — the strict
        // whitelist below was silently dropping every real Mastercard save
        // (token present, card never persisted, no error anywhere) while Visa
        // worked fine. Normalize known aliases before validating.
        $brand = strtolower((string) ($source['company'] ?? ''));
        $brand = $brand === 'master' ? 'mastercard' : $brand;
        if (! in_array($brand, ['visa', 'mastercard', 'mada'], true)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) ($source['number'] ?? ''));
        $lastFour = strlen($digits) >= 4 ? substr($digits, -4) : null;
        if (! $lastFour) {
            return null;
        }

        $expiry = (! empty($source['month']) && ! empty($source['year']))
            ? sprintf('%02d/%s', (int) $source['month'], substr((string) $source['year'], -2))
            : null;

        $card = ClientCard::query()->firstOrNew(['gateway_token' => $token]);
        $isNew = ! $card->exists;

        $card->fill([
            'client_id' => $clientId,
            'gateway' => 'moyasar',
            'gateway_token' => $token,
            'card_brand' => $brand,
            'card_holder_name' => $source['name'] ?? $transaction->customer_name,
            'last_four' => $lastFour,
            'expiry_date' => $expiry,
            'source_payment_transaction_id' => $transaction->id,
            'last_used_at' => now(),
        ]);

        if ($isNew && ! ClientCard::where('client_id', $clientId)->where('is_default', true)->exists()) {
            $card->is_default = true;
        }

        $card->save();

        return $card;
    }

    private function resolveClientId(PaymentTransaction $transaction): ?int
    {
        $fromMetadata = $transaction->response_data['client_id'] ?? null;
        if ($fromMetadata) {
            return (int) $fromMetadata;
        }

        if ($transaction->order_id && $transaction->relationLoaded('order')) {
            return $transaction->order?->client_id;
        }

        if ($transaction->order_id) {
            return \Modules\Order\Models\Order::query()
                ->whereKey($transaction->order_id)
                ->value('client_id');
        }

        return null;
    }

    private function resolveCardBrand(PaymentTransaction $transaction, array $payfortData): ?string
    {
        if ($transaction->payment_method) {
            $method = PaymentMethod::tryFrom($transaction->payment_method);
            if ($method?->supportsPayfortTokenization()) {
                return $method->value;
            }
        }

        $paymentOption = strtoupper((string) (
            $payfortData['payment_option']
            ?? $transaction->response_data['payment_option']
            ?? ''
        ));

        return match ($paymentOption) {
            'VISA' => PaymentMethod::VISA->value,
            'MASTERCARD' => PaymentMethod::MASTERCARD->value,
            'MADA' => PaymentMethod::MADA->value,
            default => null,
        };
    }

    private function extractLastFour(?string $cardNumber): ?string
    {
        if (! $cardNumber) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $cardNumber);

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }
}
