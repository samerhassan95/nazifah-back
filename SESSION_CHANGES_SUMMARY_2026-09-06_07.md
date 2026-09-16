# Session Changes Summary — 2026-09-06 / 2026-09-07

Full list of backend changes made in this session, in order. Several of
these already have their own detailed `.md` files (linked below); this
one is the single index covering everything, including the ones that
didn't get a standalone doc.

---

## 1. Discount `is_valid` / `invalid_reason` on the discounts list
`GET /user/orders/get-valid/discounts` now validates each discount
against the actual order context (items/addresses) when provided, and
returns `is_valid` + a localized `invalid_reason` (e.g. minimum order
amount not met) instead of a bare boolean.
**Commits:** `daa451c`, `cf6024b`
**Doc:** `DISCOUNT_IS_VALID_FEATURE.md`

## 2. Original delivery fee shown when delivery is free
`is_free_delivery` was already returned when a discount waives
delivery; now `original_delivery_fee` (what it would have cost) is
included alongside it, so the client UI can show that amount struck
through — in `calculate`, `validate-coupon`, tracking, order details,
and vendor's `calculate`/`show`.
**Commits:** `12b48cd`, `8f0f798` (vendor order details specifically)
**Doc:** `FREE_DELIVERY_ORIGINAL_FEE.md`

## 3. Reject delivery-type discounts on orders with no delivery leg
A "free delivery" coupon applied to a pure vendor-pickup order (no
real delivery fee to waive) used to silently succeed with zero effect.
Now explicitly rejected with a clear message, both when applying the
coupon and when re-checking an already-applied one.
**Commits:** `63308e1`, `a515222`
**Doc:** `NO_DELIVERY_DISCOUNT_REJECTION.md`

## 4. Split pickup/delivery distance (checkout preview + persisted)
`distance` only ever stored the combined pickup+delivery total.
`pickup_distance_km` / `delivery_distance_km` now returned separately
in `calculate`/`validate-coupon`, and persisted on the order itself
(new `pickup_distance`/`delivery_distance` columns) so tracking, order
details, and vendor endpoints show the split too, not just the
checkout preview.
**Commits:** `021edbb`, `d52cfe6` (**needs `php artisan migrate`**)
**Doc:** `PICKUP_DELIVERY_DISTANCE_SPLIT.md`

## 5. `calculate()` can price just one leg (pickup-only or delivery-only)
Previously required both `pickup_at_vendor` and `delivery_at_vendor`
(plus addresses) even for a preview. Now only one is required — the
other leg is simply not priced. Order creation (`store()`) is
unaffected, still requires both.
**Commit:** `19889ac`

## 6. Auto-expire stale unpaid order-edit surcharges
When a client edits an order and the increase needs a card payment,
that edit is staged until the payment completes. If never completed,
it used to sit "pending" in `payment_breakdown` forever. New scheduled
command `orders:expire-stale-modification-intents` (every 5 min)
cancels anything still pending past 5 minutes.
**Commits:** `fef8028`, `8ea8b13` (5-min default)
**Doc:** `STALE_MODIFICATION_INTENT_EXPIRY.md`

## 7. Vendor rejection → client edit → money silently lost (three-part fix)
**The big one.** When a vendor rejects part of an order, the price
drops provisionally pending the client's approval — nothing is
refunded until they approve. If the client edited the order directly
instead of approving, the edit anchored its price delta on that
provisional (never-refunded) value, permanently swallowing the
rejected amount. Fixed in three places, because the same root cause
(a stale `original_total_amount`/`original_final_amount` snapshot)
turned out to be reachable three different ways:
- **a)** A direct edit while sitting in `BRANCH_REVIEW` — anchor the
  delta on `original_final_amount` instead. **Commit `b4ca33c`**
- **b)** Same fix didn't clear `original_total_amount`, so a SECOND
  vendor-review round on the same order silently failed to
  re-snapshot (its "snapshot once" guard saw it already set) and
  fell back to the old broken behavior. **Commit `322ec28`**
- **c)** The cleanup only lived in the immediate-apply code path — a
  wallet-settled edit applies via a different path
  (`applyStagedOrderModification()`) that never got it either.
  **Commit `9fa1275`**
- **d)** Even the *correct* "tap Approve" flow
  (`finalizeClientApproval()`) never cleared the snapshot after a
  successful approval — same staleness, different trigger.
  **Commit `5b95e46`**

**Compensated live** (unrefunded vendor-rejection gap, backend bug,
1.14 SAR each unless noted): orders 508, 501, 315 (27.36), 327 (25.08),
338 (17.10), 403 (17.10), 408 (6.84), 452 (8.32), 487 (1.04). Order
513's stale `original_total_amount`/`original_final_amount` snapshot
was also manually corrected (`18.00` / `22.00`) so its in-flight review
round reconciles correctly.

## 8. Order coupon silently dropped on an edit without `coupon_code`
Editing an order without resubmitting `coupon_code` (to keep the
existing one) called the eligibility re-check with no branch context,
so any zone/branch-restricted discount (most delivery discounts) always
failed and got wiped from the order. Also, that re-check never returned
`delivery_discount_amount` at all, so even a successful re-apply lost
the delivery waiver.
**Commit:** `059ba5e`

## 9. Duplicate "pending" surcharge legs pile up on repeated edits
Retrying an edit (before completing the first one's card payment) only
marked the OLD modification intent `expired` — its payment leg was
never cancelled, so each retry left another "pending" leg behind
instead of the old one clearing.
**Commit:** `1bab4af`

## 10. Staged new total not shown when an edit needs a gateway payment
A card-paid edit surcharge stages the change until payment completes —
correct that the order's own total doesn't change yet, but nothing in
the response told the client what the total *will* become once paid
(and naively adding `amount_due` to the current total could give the
wrong number after a vendor review). Added
`payment.new_total_amount` / `payment.new_final_amount`.
**Commit:** `607cefa`

## 11. Wallet deposit wrongly marked "failed" despite a real bank approval
Moyasar's `verifyPayment()` mapped "couldn't fetch the payment from
Moyasar" (network blip, or two concurrent verify calls racing each
other — the webhook and the app's own polling endpoint) to a
*definitive* `'failed'` status. A real, bank-approved 1.00 SAR wallet
top-up (client 108) got marked failed and never credited because of
exactly this race. Now reports `'pending'` for an inconclusive lookup
instead, so a hiccup never overwrites a transaction that actually
succeeded. Compensated live (wallet credited + transaction corrected
to `completed`).
**Commit:** `e01fb53`

## 12. VAT now includes the delivery fee (rate raised to 15%)
Tax was computed on the items subtotal only — delivery was untaxed
entirely. Per explicit product decision: delivery fee (net of any
delivery discount — free delivery contributes 0 to the tax base) now
enters the VAT base too. Tax rate itself raised from 14% to 15%
(`AdminSetting: tax_percentage`).
**Commit:** `d5593a5`

## 13. Tax invoice: Arabic-only labels, discount shown as a percentage
Dropped bilingual "/ Seller", "/ Customer & Order", "(Subtotal)" etc.
suffixes from the tax invoice view — Arabic only. The discount row now
shows the effective percentage instead of a negative amount in red.
(Seller identity — showing نظيفة's own VAT/CR/address instead of the
vendor's — needs those fields filled in on the existing Dashboard
settings page at Invoice/ZATCA/WhatsApp; the backend already prefers
them over vendor data once set, no code change needed.)
**Commit:** `2ba4bc5`

## 14. Branch-review SMS/push notification reworded
"قامت المغسلة بمراجعة طلبك" (the laundry *reviewed* your order) →
"قامت المغسلة بتعديل طلبك" (the laundry *modified* your order) — in
both the order-status listener and `VendorOrderReviewService`'s
client-approval notification.
**Commit:** `855a3a2`

## 15. Translated hardcoded English messages (vendor app)
- `/vendor/vendor-profile` update success message. **Commit `c6abce5`**
- Every other hardcoded English string in the vendor `AuthController`
  (validation, errors, success messages for login/register/OTP/logout/
  profile) — reused existing `auth.*` lang keys where a close match
  existed, added new ones for the rest. **Commit `44c4fef`**

---

## Deploy notes
- `#4` needs a migration: `php artisan migrate` (adds
  `pickup_distance`/`delivery_distance` columns to `orders`).
- Everything else is a plain code deploy: `git pull && php artisan optimize:clear`.
- `#6`'s scheduled command relies on the server's cron already invoking
  `php artisan schedule:run` every minute (confirmed already running,
  since `orders:release-expired-wallet-holds` uses the same mechanism).
