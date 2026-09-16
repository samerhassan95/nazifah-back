# Wallet Deposit Verify — Response Messages

```
GET /api/v1/user/wallet/deposit/{transactionId}/verify
```

**Auth:** `Bearer <client_token>`
**Language:** determined by the `Accept-Language` header (`ar` → Arabic, anything else → English).

Source: `Modules/Payment/app/Http/Controllers/Api/V1/User/WalletController.php::verifyDeposit()`. Translation keys live in `resources/lang/{ar,en}/payment.php`.

---

## All Possible Responses

| Scenario | HTTP | Message key(s) | Arabic | English |
|---|---|---|---|---|
| Transaction not found (no matching `transaction_id` or `wallet_reference_id`) | 404 | `payment.transaction_not_found` | عملية الدفع غير موجودة | Payment transaction not found |
| Transaction belongs to a different client | 403 | `payment.unauthorized_transaction` | وصول غير مصرح به لهذه العملية | Unauthorized access to this transaction |
| Deposit already fully processed — a completed wallet transaction already exists for this payment | 200 | outer: `payment.deposit_already_verified`<br>`data.message`: `payment.deposit_already_processed` | تم التحقق من الإيداع مسبقاً<br>تم معالجة الإيداع مسبقاً | Deposit already verified<br>Deposit already processed |
| Verify + capture succeeded, but the wallet credit had already been applied (race/duplicate call) | 200 | outer: `payment.deposit_already_verified`<br>`data.message`: `payment.deposit_already_processed` | (same as above) | (same as above) |
| Verify + capture succeeded and the wallet was credited on this call | 200 | outer: `payment.deposit_verified_wallet_updated`<br>`data.message`: `payment.deposit_verified_successfully` | تم التحقق من الإيداع وتحديث المحفظة بنجاح<br>تم التحقق من الإيداع وإضافته للمحفظة بنجاح | Deposit verified and wallet updated successfully<br>Deposit verified and added to wallet successfully |
| Gateway verification succeeded (`authorized`) but capturing the hold failed | 400 | gateway's own message, or fallback `payment.payment_verification_failed` | فشل التحقق من الدفع (fallback) | Payment verification failed (fallback) |
| Wallet credit threw an exception after a successful, verified payment | 500 | `payment.failed_to_update_wallet` + exception message appended | فشل في تحديث المحفظة: *[تفاصيل الاستثناء]* | Failed to update wallet: *[exception details]* |
| Gateway says the payment is still pending | 202 | gateway's own message, or fallback `payment.payment_verification_failed` | فشل التحقق من الدفع (fallback) | Payment verification failed (fallback) |
| Gateway says the payment failed/was cancelled/refunded | 400 | gateway's own message, or fallback `payment.payment_verification_failed` | فشل التحقق من الدفع (fallback) | Payment verification failed (fallback) |
| Any unexpected exception anywhere in the method | 500 | `payment.failed_to_verify_deposit` + exception message appended | فشل في التحقق من الإيداع: *[تفاصيل الاستثناء]* | Failed to verify deposit: *[exception details]* |

---

## Notes

- The **pending/failed/authorized-capture-failed** cases return the payment gateway's own message when available (`$verificationResponse->message` / `$capture->message`), falling back to the generic `payment.payment_verification_failed` string only when the gateway didn't provide one.
- The two "success" rows (already-processed vs. newly-credited) look similar but are distinguishable by `data.transaction.status` (`"completed"` in both) and whether `data.message` is `deposit_already_processed` vs. `deposit_verified_successfully` — use that to tell a fresh credit apart from an idempotent replay.
- `data.status` at the top level is `"completed"` for both success cases (200); it mirrors the gateway status (`pending`/`authorized`/`failed`/`cancelled`/`refunded`/`partially_refunded`) for the error cases via the `data.status` field in the error payload.
