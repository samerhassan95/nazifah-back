<?php

namespace Modules\Payment\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Models\PaymentTransaction;

/**
 * Idempotent wallet top-up settlement shared by payment callback/webhook and
 * the client's deposit verify endpoint.
 */
class WalletDepositCreditor
{
    /**
     * Credit the client's wallet once for a completed deposit transaction.
     *
     * @return array{credited: bool, wallet_txn: object|null}
     */
    public function creditIfNotAlready(PaymentTransaction $transaction, string $description = 'Nathefah Wallet deposit'): array
    {
        // Cheap pre-check so a malformed transaction never opens a write transaction.
        if ((int) ($transaction->response_data['client_id'] ?? 0) <= 0) {
            return ['credited' => false, 'wallet_txn' => null];
        }

        return DB::transaction(function () use ($transaction, $description) {
            // Lock the payment row AND adopt the locked snapshot. Every settlement path
            // (public redirect callback, webhook, /verify) serializes here; reading the
            // identity/amount off the LOCKED row — not the caller's stale in-memory copy —
            // is what lets a path that lost the race observe the already-committed credit.
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->first() ?? $transaction;

            $clientId = (int) ($locked->response_data['client_id'] ?? 0);
            if ($clientId <= 0) {
                return ['credited' => false, 'wallet_txn' => null];
            }

            $lookupIds = $this->walletTransactionLookupIds($locked);
            $canonicalId = $this->canonicalWalletTransactionId($locked);

            $existing = $this->findCompletedWalletTxn($clientId, $lookupIds);
            if ($existing) {
                return ['credited' => false, 'wallet_txn' => $existing];
            }

            // Write the ledger row BEFORE moving the balance so the UNIQUE(transaction_id)
            // index is the authoritative last-resort guard: a duplicate is rejected before
            // any money moves, so a credit can never be applied twice even if two
            // settlements race on different payment rows mapping to the same reference.
            try {
                $walletTxnId = DB::table('wallet_transactions')->insertGetId([
                    'client_id' => $clientId,
                    'type' => 'credit',
                    'amount' => $locked->amount,
                    'payment_method' => $locked->payment_method ?? 'unknown',
                    'description' => $description,
                    'transaction_id' => $canonicalId,
                    'status' => 'completed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost the race: another path already credited this exact deposit. No
                // balance was touched (the insert precedes the increment), so report the
                // winning credit and stay idempotent instead of surfacing a 500. Relies on
                // MySQL/InnoDB statement-level rollback keeping this transaction/savepoint
                // usable; PostgreSQL would need an explicit savepoint rollback here.
                return ['credited' => false, 'wallet_txn' => $this->findCompletedWalletTxn($clientId, $lookupIds)];
            }

            DB::table('clients')
                ->where('id', $clientId)
                ->increment('wallet_balance', $locked->amount);

            $bonusAmount = round((float) ($locked->response_data['wallet_bonus_amount'] ?? 0), 2);
            if ($bonusAmount > 0 && ! empty($locked->response_data['wallet_bonus_discount_id'])) {
                $bonusTxnId = $this->bonusWalletTransactionId($locked);
                try {
                    DB::table('wallet_transactions')->insert([
                        'client_id' => $clientId,
                        'type' => 'credit',
                        'amount' => $bonusAmount,
                        'payment_method' => 'discount_bonus',
                        'description' => 'Nathefah Wallet top-up bonus',
                        'transaction_id' => $bonusTxnId,
                        'status' => 'completed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('clients')
                        ->where('id', $clientId)
                        ->increment('wallet_balance', $bonusAmount);
                } catch (UniqueConstraintViolationException $e) {
                    // Bonus already credited for this deposit.
                }
            }

            return [
                'credited' => true,
                'wallet_txn' => DB::table('wallet_transactions')->where('id', $walletTxnId)->first(),
            ];
        });
    }

    public function findCompletedWalletTxn(int $clientId, array $lookupIds): ?object
    {
        if ($lookupIds === []) {
            return null;
        }

        return DB::table('wallet_transactions')
            ->where('client_id', $clientId)
            ->whereIn('transaction_id', $lookupIds)
            ->where('status', 'completed')
            ->first();
    }

    /** @return string[] */
    public function walletTransactionLookupIds(PaymentTransaction $transaction): array
    {
        return array_values(array_unique(array_filter([
            $transaction->transaction_id,
            $transaction->response_data['wallet_reference_id'] ?? null,
        ])));
    }

    public function canonicalWalletTransactionId(PaymentTransaction $transaction): string
    {
        // Anchor the ledger dedupe key on the IMMUTABLE payment_transactions.transaction_id
        // column (itself UNIQUE), NOT on the mutable response_data['wallet_reference_id']
        // — the public /payments/callback route could otherwise persist a forged
        // wallet_reference_id and fork the dedupe key into a double credit. For wallet
        // deposits the two are equal at init, so this only removes the attack surface.
        return (string) (
            $transaction->transaction_id
            ?? $transaction->response_data['wallet_reference_id']
        );
    }

    private function bonusWalletTransactionId(PaymentTransaction $transaction): string
    {
        return $this->canonicalWalletTransactionId($transaction).'-BONUS-'.(int) ($transaction->response_data['wallet_bonus_discount_id'] ?? 0);
    }
}
