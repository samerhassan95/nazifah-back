# Reject Delivery Discounts on No-Delivery Orders

## The problem
A "free delivery" (or any delivery-type) discount code waives the
delivery fee. On an order where the client picks up from / drops off at
the vendor in person (`pickup_at_vendor` and `delivery_at_vendor` both
true), there was never a delivery fee to begin with — but applying such
a coupon used to silently "succeed" with a **zero-value discount**
instead of telling the client it doesn't apply here.

## What changed
Any delivery-type discount (`discount_type = delivery_free`, or a
percentage discount flagged `applies_to_delivery` / kind
`delivery_discount`) is now explicitly **rejected** when the order has
no delivery leg at all, with a clear message — instead of being silently
accepted for no effect.

### Message returned
- Arabic: `هذا الكود مخصص لخصم التوصيل، وهذا الطلب استلام من المغسلة وليس توصيل، فلا يوجد رسوم توصيل لتطبيق الخصم عليها`
- English: `This is a delivery discount, but this order is a vendor pickup — there is no delivery fee to discount`

## Where it applies
Covers both the moment a coupon is validated/applied **and** any later
re-check (e.g. the client changes pickup/delivery mode mid-edit while a
delivery discount is already applied):

| Endpoint | Method |
|---|---|
| `POST/GET /user/orders/calculate` | `validateAndCalculateDiscount()` (apply) + `evaluateKnownOrderDiscount()` (re-check) |
| `POST /user/orders` (store) | same, both paths |
| `POST /user/orders/validate-coupon` | `validateAndCalculateDiscount()` |
| `GET /user/orders/get-valid/discounts` | `validateAndCalculateDiscount()` — now also accepts optional `pickup_at_vendor` / `delivery_at_vendor` inputs to compute `is_valid` accurately |
| `PUT /user/orders/{id}/update` | both paths |
| `POST /vendor/orders/{id}/calculate` | `evaluateKnownOrderDiscount()` |

## How it works
`DiscountService::validateAndCalculateDiscount()` and
`evaluateKnownOrderDiscount()` now take an optional trailing
`bool $hasDelivery = true`. Every call site above already knows
`pickup_at_vendor` / `delivery_at_vendor` at the point it validates a
coupon, so it passes `! ($pickupAtVendor && $deliveryAtVendor)`. The
shared evaluator (`evaluateOrderDiscount()`) rejects the discount
outright when it's delivery-type and `has_delivery` is false — before
ever computing a (zero) discount amount.

Fully backward compatible: `$hasDelivery` defaults to `true`, so any
caller that doesn't pass it keeps the previous behavior.

## Files changed
- `Modules/Discount/app/Services/DiscountService.php`
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderController.php`
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php`
- `Modules/Vendor/app/Http/Controllers/Api/V1/OrderController.php`

## Commits
- `63308e1` — reject on apply (`validateAndCalculateDiscount`)
- `a515222` — reject on re-check too (`evaluateKnownOrderDiscount`)
