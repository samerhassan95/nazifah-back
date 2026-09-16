# Free Delivery — Original Fee for Strikethrough

## What changed
Every endpoint that already flags `is_free_delivery: true` now also returns
`original_delivery_fee` alongside it — what delivery would have cost before
the discount waived it. The client UI can show that amount with a
strikethrough next to "free."

`original_delivery_fee` is only present when:
- `is_free_delivery` is `true`, **and**
- there was a real, non-zero delivery leg to begin with (a genuinely
  address-less pickup/drop-off at the vendor, with zero distance, still
  reports only `is_free_delivery` — there was never a real fee to strike
  through).

When delivery isn't free, neither field is present (unchanged behavior).

## Endpoints affected

| Endpoint | Field location |
|---|---|
| `GET/POST /user/orders/calculate` | `summary.original_delivery_fee` |
| `POST /user/orders/validate-coupon` (shares the same summary builder) | `summary.original_delivery_fee` |
| `GET /user/orders/{orderId}/tracking` | top-level `original_delivery_fee` |
| `POST /vendor/orders/{orderId}/calculate` | `summary.original_delivery_fee` |

## Example
```json
{
  "delivery_fee": 0.0,
  "is_free_delivery": true,
  "original_delivery_fee": 8.50,
  "final_amount": 12.42
}
```

## How it's computed
- **`calculate` / `validate-coupon` (checkout preview, no order yet):** the
  raw distance × per-km delivery fee that was computed *before* the
  discount's `calculatePricingTotals()` netted it to 0 — this is the exact
  amount the discount waived on this specific calculation.
- **`tracking` (an existing, already-created order):** the order only
  stores the *net* (post-discount) `delivery_fee` — there's no column for
  what it was before. Recomputed the same way checkout did it:
  `order.distance × vendor.delivery_price_per_km` (falling back to the
  admin default rate). This matches what was actually charged unless the
  vendor's per-km rate has changed since the order was placed.
- **`vendor calculate` (reviewing an existing order):** same recompute as
  tracking, since this endpoint's `deliveryFees['delivery_fee']` input is
  also the order's already-net stored value, not the pre-discount amount.

## Files changed
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderController.php` —
  `buildOrderPricingSummary()`
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php`
  — new `freeDeliveryFields()` helper, used in the tracking response
- `Modules/Vendor/app/Http/Controllers/Api/V1/OrderController.php` —
  `calculate()`

## Commit
- `12b48cd` — add `original_delivery_fee` next to `is_free_delivery`
  across checkout preview, tracking, and vendor review endpoints.
