<?php

return [
    // Success messages
    'methods_retrieved' => 'Payment methods retrieved successfully',
    'payment_successful' => 'Payment completed successfully',
    'payment_failed' => 'Payment failed',
    'payment_pending' => 'Payment is pending',
    'payment_cancelled' => 'Payment was cancelled',

    // Payment method labels
    'cash_on_delivery' => 'Cash on Delivery',
    'visa' => 'Visa',
    'mastercard' => 'MasterCard',
    'mada' => 'MADA',
    'credit_card' => 'Digital Payment',
    'nazefah_wallet' => 'Nathefah Wallet',
    'stc_pay' => 'STC Pay',
    'samsung_pay' => 'Samsung Pay',

    // Error messages
    'method_not_found' => 'Payment method not found',
    'insufficient_balance' => 'Insufficient wallet balance',
    'invalid_card' => 'Invalid card details',

    // Status and sorting
    'status_updated' => 'Payment method status updated successfully',
    'sort_order_updated' => 'Payment methods sort order updated successfully',
    'update_failed' => 'Failed to update payment method status',
    'sort_order_failed' => 'Failed to update sort order',
    'retrieval_failed' => 'Failed to retrieve payment methods',

    // Wallet Deposit Verification
    'transaction_not_found' => 'Payment transaction not found',
    'unauthorized_transaction' => 'Unauthorized access to this transaction',
    'deposit_already_processed' => 'Deposit already processed',
    'deposit_already_verified' => 'Deposit already verified',
    'deposit_verified_successfully' => 'Deposit verified and added to wallet successfully',
    'deposit_verified_wallet_updated' => 'Deposit verified and wallet updated successfully',
    'payment_verification_failed' => 'Payment verification failed',
    'failed_to_update_wallet' => 'Failed to update wallet',
    'failed_to_verify_deposit' => 'Failed to verify deposit',

    // Moyasar
    'embedded_payment_initialized' => 'Embedded payment initialized — render the moyasar.js form with `moyasar`.',
    'awaiting_moyasar_payment_confirmation' => 'Awaiting Moyasar payment confirmation (no payment id yet).',
    'could_not_retrieve_payment' => 'Could not retrieve the payment from Moyasar.',
    'moyasar_description_wallet' => 'Nathefah - Wallet Top-up',
    'moyasar_description_order' => 'Nathefah - Laundry Service Payment',

    // Payment status short labels (API payment_status_label)
    'status_completed' => 'Completed',
    'status_pending' => 'Pending',
    'status_failed' => 'Failed',
    'status_not_initiated' => 'Not Initiated',

    // Wallet transaction history
    'wallet_txn_deposit' => 'Wallet Deposit',
    'wallet_txn_order_payment' => 'Order #:order payment via wallet',
    'wallet_txn_order_payment_awaiting_card' => 'Order #:order payment via wallet (awaiting card payment)',
    'wallet_txn_order_payment_awaiting_gateway' => 'Order #:order payment via wallet (awaiting gateway payment)',
    'wallet_txn_order_payment_reserved' => 'Order #:order wallet amount reserved',
    'wallet_txn_order_surcharge' => 'Order #:order surcharge via wallet',
    'wallet_txn_order_surcharge_awaiting' => 'Order #:order surcharge via wallet (awaiting payment)',
    'wallet_txn_order_update_charge' => 'Additional charge for order #:order update',
    'wallet_txn_order_deleted' => 'Refund for deleted order #:order',
    'wallet_txn_order_refund' => 'Refund for order #:order',
    'wallet_txn_order_generic' => 'Order #:order',
    'wallet_txn_addition' => 'Addition',
    'wallet_txn_deduction' => 'Deduction',
];
