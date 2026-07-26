<?php

namespace Modules\Order\Exceptions;

/**
 * Thrown when a wallet payment leg exceeds the client's available balance.
 * Callers translate this into a 402 "payment required" response listing the
 * gateway methods the customer can use to cover the amount instead.
 */
class InsufficientWalletBalanceException extends \RuntimeException
{
    public function __construct(
        public readonly float $amountDue,
        public readonly float $available,
        string $message = 'Insufficient wallet balance'
    ) {
        parent::__construct($message);
    }
}
