# `payment_breakdown.payments`: `amount_before` / `amount_after`

Supersedes the `refunded_amount` / `refunded_to` / `refunded_to_label` fields documented in `REFUND_WALLET_CHANGES.md` (§2) — those are removed. Nothing else in `payment_breakdown` changed.

**Applies to:** `payment_breakdown.payments` wherever it appears (`GET /vendor/orders/{id}`, etc.).

Each entry in `payments` now reports the price before and after any refund on that payment method, instead of the refund delta and its destination:

```json
{
  "payment_method": "nazefah_wallet",
  "payment_method_label": "محفظة",
  "amount": 32.68,
  "status": "paid",
  "amount_before": 55.00,
  "amount_after": 32.68,
  "status_label": "مكتمل"
}
```

- `amount_before` — what was originally charged via this method, before any refund.
- `amount_after` — what's left via this method after any refund (same as `amount` in almost every case — see note below).

For a method with no refund at all, `amount_before` and `amount_after` are equal (nothing changed). Confirmed live on a split-payment order (wallet 55.00 → 32.68 after a partial refund; visa 3.68 → 0 after a full refund):

```json
"payments": [
  {
    "payment_method": "nazefah_wallet",
    "amount": 32.68,
    "status": "paid",
    "amount_before": 55.00,
    "amount_after": 32.68
  },
  {
    "payment_method": "visa",
    "amount": 3.68,
    "status": "refunded",
    "amount_before": 3.68,
    "amount_after": 0.0
  }
]
```

**Note on the visa row:** `amount` stays `3.68` (what was refunded, per the earlier fix that keeps fully-refunded legs visible) while `amount_after` is `0.0` — `amount` is not simply an alias for `amount_after`. For a `status: "paid"` row (nothing outstanding to net out) the two do match, as in the wallet row above.
