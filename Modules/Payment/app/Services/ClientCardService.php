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
     * Persist a Moyasar-tokenized card. Moyasar only returns source.token when the
     * payer ticked "save card", so a token's presence is the opt-in signal. CVC is
     * never stored — Moyasar tokens don't carry it.
     */
    public function upsertFromMoyasarSource(PaymentTransaction $transaction, array $source): ?ClientCard
    {
        $token = $source['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        $clientId = $this->resolveClientId($transaction);
        if (! $clientId) {
            return null;
        }

        $brand = strtolower((string) ($source['company'] ?? ''));
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
