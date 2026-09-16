<title>Pickup/Delivery Distance Split</title>
# Pickup/Delivery Distance Split

## The problem
`distance` only ever stored the combined pickup+delivery total. That's
meaningless once pickup and delivery are two different addresses (drop
off at home, deliver to the office) — there was no way to tell how much
of that number was which leg. `pickup_fee` / `delivery_fee_amount`
already existed as separate fields; distance never got the same
treatment.

## What changed
Two new columns, `pickup_distance` / `delivery_distance`, are now
persisted on the order (migration
`2026_09_06_000001_add_pickup_delivery_distance_to_orders_table.php`)
and exposed as `pickup_distance_km` / `delivery_distance_km` — kept
alongside the existing `distance` / `total_distance_km` fields, which
are unchanged, for backward compatibility.

### Where they're populated
- **Order creation** (`OrderController::store()`) — both the
  gateway/`PendingOrder` path and the direct `Order::create()` path.
- **Client edits** (`OrderTrackingController::updateOrder()`) — both
  the immediate-apply path and the gateway-deferred staged path (via
  `OrderModificationIntent.staged_pricing`, applied once the surcharge
  is paid).

### Where they're returned
Every response that already showed `distance`/`total_distance_km` now
also returns the split:

| Endpoint | Field location |
|---|---|
| `GET/POST /user/orders/calculate` | `summary.pickup_distance_km` / `summary.delivery_distance_km` (already shipped earlier) |
| `POST /user/orders/validate-coupon` | same (shares the summary builder) |
| `POST /user/orders` (store) | top-level, both the pending-order and direct-order response shapes |
| `GET /user/orders/{orderId}/tracking` | top-level |
| `PUT /user/orders/{orderId}/update` | top-level, in the response |
| Order details / on-the-way card (`OrderController`) | top-level |
| `POST /vendor/orders/{orderId}/calculate` | `summary.pickup_distance_km` / `summary.delivery_distance_km` — now reads the real stored per-leg distance instead of having no split at all |
| `GET /vendor/orders/{orderId}` (show) | top-level |

Vendor's fee split for an existing order stays the existing 50/50
approximation of the stored `delivery_fee` (unchanged, deliberately —
recomputing the fee from raw distance was already avoided to prevent
float drift from `orders.delivery_fee`). Only the **distance** display
was broken; that's what's fixed here.

### Existing orders (created before this deploy)
`pickup_distance` / `delivery_distance` are nullable and will read as
`0` for any order created before this migration — there's no way to
retroactively split a total that was never split at creation. Every
order created or edited from here on gets the real split.

## Deploy step
This needs a migration:
```bash
git pull && php artisan migrate
```

## Files changed
- `Modules/Order/database/migrations/2026_09_06_000001_add_pickup_delivery_distance_to_orders_table.php` (new)
- `Modules/Order/app/Models/Order.php` — fillable + casts
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderController.php`
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php`
- `Modules/Order/app/Services/OrderPaymentService.php` — `applyStagedOrderModification()`
- `Modules/Vendor/app/Http/Controllers/Api/V1/OrderController.php`

## Commits
- `021edbb` — split distance in `calculate()`/`validate-coupon` (checkout preview only)
- `d52cfe6` — persist the split on the order itself, everywhere else
