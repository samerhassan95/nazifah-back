<title>Items Change Summary</title>
# Items Change Summary on Order Edit

## What it does
`PUT /user/orders/{id}/update` now returns `data.items_change_summary`
alongside `order` and `payment` — a plain breakdown of what the edit
changed at the item level, right when the client submits it:

- which line(s) were **removed** and what they were worth
- which line(s) were **added** and what they cost
- the **net** item-level effect: refund, charge, or a wash (net zero)

This is independent of `payment.amount_due` — that field is the real,
tax-inclusive amount actually due/refunded for the whole edit.
`items_change_summary` is a plain-language, pre-tax breakdown of *why*
that number is what it is.

## Response shape
```json
{
  "data": {
    "order": { "...": "..." },
    "payment": { "...": "..." },
    "items_change_summary": {
      "removed_items": [
        { "name": "بنطال - كي الملابس بالبخار", "amount": 3.00 }
      ],
      "removed_total": 3.00,
      "added_items": [
        { "name": "فستان - غسيل فاخر", "amount": 2.00 }
      ],
      "added_total": 2.00,
      "net_amount": -1.00,
      "net_type": "refund"
    }
  }
}
```

`net_type` is one of:
- `"refund"` — removed more value than was added (`net_amount < 0`)
- `"charge"` — added more value than was removed (`net_amount > 0`)
- `"none"` — the two sides cancel out exactly (e.g. one item swapped
  for another of the same price — see the walkthrough in
  `BLANK_WEBVIEW_AFTER_EDIT_ISSUE.md`)

`removed_items` / `added_items` can be empty arrays (e.g. a pure
addition has no `removed_items`).

## How it's computed
The order's item list **before** this edit is compared against the
new list being submitted, matched by `piece_id` + `service_id`:
- a line present in the old list but not the new one → **removed**,
  valued at its old `total_price`
- a line present in the new list but not the old one → **added**,
  valued at its new `total_price`
- a line present in both → unchanged, not shown in either list

This is a line-level diff, not a full field-by-field comparison — if
the same piece+service stays but its *additional services* change,
that's currently treated as "unchanged" (not shown as removed+added).
Quantity changes on an unchanged piece+service are also not broken out
separately today.

## Files changed
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php`
  — `updateOrder()`

## Commit
- `7d1bb73`
