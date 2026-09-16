<title>Order Update Preview</title>
# Order Update Preview (calculate before commit)

## What it does
A new, **read-only** endpoint lets the client preview an order edit —
new totals, what's original vs added/removed, and the exact
refund/charge — **before** the user taps "confirm changes" / "تنفيذ
التعديلات". Nothing is written to the database, no payment is
touched, no items are changed. It's the same request body as the real
update, just a dry run.

```
POST /api/v1/user/orders/{order_id}/update/calculate
```

Body: identical shape to `PUT /api/v1/user/orders/{order_id}/update`
(`items`, `pickup_at_vendor`, `delivery_at_vendor`,
`pickup_address_id`, `delivery_address_id`, `notes`, `coupon_code`).

## Why it's separate from `/update`
`/update` **commits** the edit: it writes the new items, moves money
(refund, surcharge, wallet hold, gateway charge), and changes the
order's status. There was no way for the client to see what an edit
would cost/refund without actually committing it.

`/update/calculate` computes the exact same numbers using the exact
same pricing logic, but stops before any of that — it's purely
informational.

## Response shape
```json
{
  "data": {
    "items_breakdown": [
      { "name": "بنطال - كي بخار", "amount": 22.00, "status": "added" },
      { "name": "فستان - غسيل فاخر", "amount": 3.00, "status": "original" }
    ],
    "added_total": 22.00,
    "removed_total": 0,
    "net_amount": 22.00,
    "net_type": "charge",
    "subtotal": 24.00,
    "discount_amount": 0,
    "delivery_fee": 0,
    "tax_amount": 3.60,
    "total_amount": 27.60,
    "amount_due": 24.15,
    "amount_due_type": "charge"
  }
}
```

One flat object — no nested sub-objects to dig through:

- **`items_breakdown`**: every component (each main service, each
  additional service — old or new) tagged `original` / `added` /
  `removed`. This is what drives the "grey out originals, highlight
  what changed" UI.
- **`added_total` / `removed_total` / `net_amount` / `net_type`**:
  item-level "cart" diff, **before** tax/delivery — how much was
  added vs removed and the net of the two. `net_type` is `refund` /
  `charge` / `none`. This also picks up a same-line change (e.g. an
  additional service added to an existing piece+service line) —
  earlier that case was silently invisible (added_total stayed 0)
  because the line's signature didn't change; now the line's value
  delta is attributed to whichever side it belongs on.
  A multi-service line (two+ services on one piece) is matched by its
  **full** service set, not just the first service — matching on one
  service alone made every other service on the same line look
  "removed" whenever the line was resubmitted unchanged.
- **`subtotal` → `total_amount`**: the new order's totals if this
  edit is confirmed as-is (same fields `/update` writes to the
  order).
- **`amount_due` / `amount_due_type`**: the one number that matters
  for "how much will I actually pay/get back" — the **net** delta
  (`total_amount − old order total`), never the old total stacked on
  top of the new one. This is different from `net_amount` above:
  `net_amount` is the pre-tax item-level diff, `amount_due` is the
  real post-tax/delivery money that moves.

**UI mapping note**: `total_amount` is the whole order's total (every
item, changed or not) — not what belongs in a "review this edit" card.
For that card, two options:
- **`net_tax_amount` / `net_total_amount`** — a self-consistent group
  with `added_total`/`removed_total`/`net_amount`: `net_tax_amount =
  net_amount × tax rate`, `net_total_amount = net_amount +
  net_tax_amount`. Use these if the card's "الضريبة"/"الإجمالي" rows
  should read as simple functions of "الصافي" right above them.
- **`amount_due` / `amount_due_type`** — the actual amount that gets
  charged/refunded when the edit is confirmed. This is usually equal
  to `net_total_amount`, but **not always**: it also reconciles a
  pending vendor-rejected item this same edit resolves (order in
  `branch_review` with an unapproved review — see
  `$resolvesPendingVendorReview` in the code), and, very rarely, an
  order whose tax rate changed since it was placed. When they diverge,
  `amount_due` is the number that's actually correct to charge/refund
  — `net_total_amount` is a display simplification for the card, not
  a second source of truth.

## Guarantee: preview and commit can never disagree
Both `/update` and `/update/calculate` call the exact same private
method (`resolveOrderUpdateComputation()`) to do the pricing/discount/
items-diff math. Only `/update` goes on to actually write the order,
move money, or touch payment/wallet — `/update/calculate` returns
right after the computation with no side effects.

This was a deliberate refactor (not a duplicate implementation)
specifically so the numbers shown in the preview can never drift from
what actually gets charged/refunded when the user confirms — the same
class of bug this session spent a lot of time fixing in `/update`
itself (see `ITEMS_CHANGE_SUMMARY_FEATURE.md` and
`SESSION_CHANGES_SUMMARY_2026-09-06_07.md`) would have been trivial to
reintroduce by hand-writing a second copy of this logic.

## Verified on production
Order 523:
- Same item resubmitted unchanged → correctly classified `original`,
  `net_type: none` when nothing actually changed price-wise.
- Item added alongside the existing one → correctly classified
  `added`, `amount_due` matched the true delta exactly
  (`new_final − old_final`), not the added item's price alone.
- A catalog price change since the order was placed (piece 42/service
  66 dropped from 3.00 → 2.00) was correctly reflected in the
  preview — same re-pricing behavior `/update` has always had.

Order 512 (multi-service piece, additional service added to an
existing line):
- Before the fixes below: `added_total` came back `0` even though a
  real 1.00 addition was made, and the line's second service showed
  as a phantom `removed` entry it never was.
- After: `added_total: 1`, no phantom `removed` entry — matches
  exactly what was added.

## Resolved limitations
The diff went through three iterations while testing against real
orders, each fixing what the previous one got wrong (all three also
affected the already-shipped `items_change_summary` on `/update`
itself, since they share this code):
1. A same-signature value change (additional service or quantity
   added/removed on an otherwise-unchanged line) was invisible —
   silently dropped from added_total/removed_total.
2. A multi-service line (2+ services on one piece) was matched by its
   *first* service only, so every other service on that line looked
   spuriously "removed" whenever the line was resubmitted unchanged.
3. Matching by the line's *full* service set (the fix for #2) broke
   down the moment a service was added to or dropped from an existing
   multi-service line — the whole old line looked "removed" and the
   whole new line "added" (order 512: adding one service to a 2-service
   line showed `removed_total: 36` / `added_total: 44` for what was
   really a small addon swap worth a few riyals — correct net, wildly
   misleading breakdown).

**Final design**: diff at the atomic component level — each main
service and each additional service is its own component
(`piece_id` + `service_id`, or `piece_id` + `service_addition_id`),
independent of what else is on the same line. `items_breakdown` now
shows every component from either side: unchanged ones as `original`,
and only what actually changed as `added`/`removed` — nothing else
gets dragged in.

## Files changed
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php`
  — extracted `resolveOrderUpdateComputation()`, added
  `calculateOrderUpdate()`
- `routes/api/v1/user.php` — new route
- `resources/lang/ar/order.php`, `resources/lang/en/order.php` —
  `order_calculated` key

## Commit
- `5f3398d`
