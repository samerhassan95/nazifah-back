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
    'digital_payment' => 'Digital Payment',
    'nazefah_wallet' => 'Wallet',
    'stc_pay' => 'STC Pay',
    'apple_pay' => 'Apple Pay',
    'google_pay' => 'Google Pay',
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

    // Moyasar failure messages (API returns English; we localize)
    'moyasar_error_generic' => 'Payment failed. Please try again.',
    'moyasar_error_3ds_declined' => 'Card authentication (3DS) was declined. Please try again or use another card.',
    'moyasar_error_3ds_auth' => 'Authentication was unsuccessful or cancelled by the cardholder. Please try again.',
    'moyasar_error_3ds_not_enrolled' => 'This card is not enrolled in 3DS. Contact your bank to enable online payments.',
    'moyasar_error_3ds_timeout' => '3DS authentication timed out. Please try again.',
    'moyasar_error_3ds_connection' => 'Could not connect to the 3DS service. Please try again later.',
    'moyasar_error_3ds_busy' => 'The 3DS service is busy. Please try again shortly.',
    'moyasar_error_3ds_generic' => 'Card 3DS authentication failed. Please try again.',
    'moyasar_error_3ds_unsupported_device' => 'This device is not supported for 3DS authentication.',
    'moyasar_error_3ds_frequency' => 'Authentication frequency limit exceeded. Please try again later.',
    'moyasar_error_3ds_rejected' => 'The issuer bank rejected the authentication attempt. Please use another card.',
    'moyasar_error_3ds_unavailable' => 'Authentication is unavailable. Try again later or contact your bank.',
    'moyasar_error_3ds_session_expired' => 'The authentication session expired. Please try again.',
    'moyasar_error_insufficient_funds' => 'Insufficient funds on the card.',
    'moyasar_error_declined' => 'The transaction was declined by the customer’s bank. Please use another card.',
    'moyasar_error_blocked' => 'The bank blocked this transaction. It may be suspected fraud.',
    'moyasar_error_expired_card' => 'The card has expired. Please use another card.',
    'moyasar_error_invalid_card' => 'Invalid card number. Please check and try again.',
    'moyasar_error_invalid_cvc' => 'Invalid security code (CVC/CVV).',
    'moyasar_error_timed_out' => 'Could not connect to the customer’s bank. Please try again.',
    'moyasar_error_unspecified' => 'The bank declined the transaction for an unspecified reason. Please use another card.',
    'moyasar_error_referred' => 'The customer’s bank indicated a problem with the card.',
    'moyasar_error_timeframe_expired' => 'The allowed time to complete payment has expired. Please try again.',
    'moyasar_error_stolen_card' => 'This card was reported stolen. Please use another card.',
    'moyasar_error_fraud' => 'The transaction was declined as suspected fraud.',
    'moyasar_error_amount_exceeded' => 'The amount exceeds the maximum allowed per transaction.',

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
    'wallet_txn_order_deleted' => ':amount SAR refunded after order #:order was deleted',
    'wallet_txn_order_refund' => ':amount SAR refunded from order #:order',
    'wallet_txn_order_cancelled_refund' => ':amount SAR refunded after order #:order was cancelled',
    'wallet_txn_order_generic' => 'Order #:order',
    'wallet_txn_addition' => 'Addition',
    'wallet_txn_deduction' => 'Deduction',
];
