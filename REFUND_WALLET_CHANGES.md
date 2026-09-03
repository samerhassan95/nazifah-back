# Refund Routing & Wallet Description Changes

Two related backend changes: where refund money goes when an order was paid with more than one method, and what the client sees in their wallet history when a refund happens.

---

## 1. Split-Payment Refunds (Price Decrease) Now Go Entirely to the Wallet

**Applies to:** `OrderPaymentService::refundDecrease()` — fires when a price decrease needs refunding (vendor rejects/reduces items during review). **Does not apply to order cancellation** — see below.

**Before:** if an order was paid through more than one method (e.g. wallet + card), a decrease refund was split — card legs refunded first via the gateway (back to the card), then any remainder credited to the wallet.

**Now:** if the order was paid through more than one real-money method, the **entire** refund is credited to the client's wallet — no gateway refund call is made at all for that leg. A single-method order still refunds through that same method exactly as before (card → card, wallet → wallet).

| Scenario | Refund destination |
|---|---|
| Paid via wallet + card (split), price decreases | **All to wallet** |
| Paid via card only, price decreases | Card (gateway refund; falls back to wallet only if the gateway rejects it) |
| Paid via wallet only, price decreases | Wallet |
| Paid via COD | Never refunded as money — the amount still owed at delivery is just reduced |

**Order cancellation is unaffected — this was already correct.** `OrderPaymentService::refundOrderOnCancellation()` always refunds each leg back to its own original method regardless of how many were used (card leg → card, wallet leg → wallet). That behavior was already in place and was not touched by this change.

---

## 2. `payment_breakdown` Reports the True Refund Destination

Follow-up so the API stays accurate given #1: each entry in `payment_breakdown.payments` (`GET /vendor/orders/{id}`, etc.) already carries `refunded_amount`. It now also carries `refunded_to` / `refunded_to_label`, which reflect where the money **actually** went — not always the same as the leg's own `payment_method`. A card leg whose refund got routed to the wallet (per #1, or because a gateway refund attempt failed) shows `refunded_to: "nazefah_wallet"` even though `payment_method` on that same entry still says `"visa"`.

```json
{
  "payment_method": "visa",
  "amount": 0.0,
  "refunded_amount": 3.68,
  "refunded_to": "nazefah_wallet",
  "refunded_to_label": "محفظة"
}
```

---

## 3. Wallet Transaction Descriptions Now Include the Amount

**Applies to:** the client wallet history (`GET /user/wallet`, `GET /user/wallet/transactions`) — the `description` field on each transaction row.

**Before:** a refund's description only named the order, not how much: *"استرداد لطلب #ORD-X"* / *"Refund for order #X"*. The client had to look at the separate `amount` field themselves to know how much came back.

**Now:**

| Case | Arabic | English |
|---|---|---|
| Decrease refund | تم استرداد مبلغ **22.32** ر.س من الطلب رقم #ORD-X | **22.32** SAR refunded from order #ORD-X |
| Order deleted refund | تم استرداد **22.32** ر.س بعد حذف الطلب رقم #ORD-X | **22.32** SAR refunded after order #ORD-X was deleted |
| Order cancelled refund | تم استرداد **22.32** ر.س بعد إلغاء الطلب رقم #ORD-X | **22.32** SAR refunded after order #ORD-X was cancelled |

**Side fix bundled in:** a cancellation refund's stored internal note never contained the word "refund" (it's saved as "... Order cancelled"), so it used to fall through to a generic, unlabeled *"طلب #X"* description instead of reading as a refund at all. It's now explicitly detected and gets its own wording (the cancelled-refund row above).

**No API shape change** — `description` was already a string field; its content just changed. Nothing new to parse.
