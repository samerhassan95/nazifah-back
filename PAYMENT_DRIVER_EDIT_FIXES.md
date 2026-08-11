# QA Fixes — Credit Card Label, Driver Reassignment, Order Edit Lock

**Date:** 2026-08-11
**Repo:** `samerhassan95/nazifah-back`

---

## Summary

Three items from the Nazefah QA note were investigated and fixed:

1. **#7 — Paying by credit card shows the wrong payment method** (`visa` instead of `credit_card`).
2. **#12 — Vendor should be able to cancel the assigned driver and reassign the order to another driver.**
3. **#5 — Order should stay editable until the driver accepts the pickup.**

---

## Fix 1 (QA #7) — Credit card payments were silently relabeled "Visa"

### Cause

When Moyasar is the active gateway, the client is shown a single generic **"Credit Card"** tile (`payment_method = credit_card`) instead of individual brands — see `Modules/Payment/app/Models/PaymentMethod.php::collapseGatewayMethodsForMoyasar()`.

At checkout, `OrderPaymentService::normalizePaymentMethodAlias()` unconditionally rewrote `credit_card` → `visa` **before** the customer ever entered their card on Moyasar's hosted page. That aliased value was what got written to `orders.payment_method`, `orders.payment_methods`, `payment_transactions.payment_method`, and `order_payments.payment_method` — and nothing downstream (including the Moyasar webhook handler) ever corrected it. So every card payment was reported back as `"visa"` regardless of the actual card used.

The alias existed because Payfort/APS has no generic "credit card" option — it needs a concrete brand (`VISA`/`MASTERCARD`/`MADA`) for its `payment_option` parameter. Moyasar, on the other hand, already had a `PaymentMethod::CREDIT_CARD` case that correctly maps to its generic `creditcard` source (`getMoyasarSource()`), so the alias wasn't needed there — it just was never made conditional.

### Fix

- `OrderPaymentService::normalizePaymentMethodAlias()` (`Modules/Order/app/Services/OrderPaymentService.php`) now only aliases `credit_card` → `visa` when **Payfort/APS** is the active gateway. When **Moyasar** is active, `credit_card` is kept as-is.
- `OrderPaymentService::gatewayMethods()` now includes `credit_card` so it passes split/surcharge validation (`allowedLegMethods()` / `allowedSurchargeMethods()`).
- `PaymentMethod::requiresPayfort()` (`app/Enums/PaymentMethod.php`) now includes `credit_card`, so `createGatewayLeg()` / `createGatewayLegForPendingOrder()` no longer reject it before initiating the gateway payment.
- `getPayfortPaymentOption()` still returns `null` for `credit_card` — intentional, this tells Moyasar not to restrict the hosted page to one card scheme (`MoyasarGateway::mapToMoyasarAllowedMethods()` already special-cases this).
- Documented the `credit_card` value in `PAYMENT_METHODS_AND_STATUSES.md`.

### Affected files

| File | Change |
|------|--------|
| `Modules/Order/app/Services/OrderPaymentService.php` | Conditional alias, `credit_card` added to `gatewayMethods()` |
| `app/Enums/PaymentMethod.php` | `credit_card` added to `requiresPayfort()` |
| `PAYMENT_METHODS_AND_STATUSES.md` | Documented `credit_card` value |

### How to verify

1. Ensure Moyasar is the active gateway (`GET /api/v1/admin/payment-gateways`).
2. Create an order choosing the "Credit Card" option (`payment_methods: ["credit_card"]`).
3. Check the order / transaction response — `payment_method` should be `"credit_card"`, not `"visa"`.
4. Switch active gateway to `amazon_pay` (Payfort) and repeat — `payment_method` should still resolve to `"visa"` (Payfort has no generic option), matching prior behavior.

---

## Fix 2 (QA #12) — Vendor reassigning a driver

### What already existed

Vendor-side driver reassignment was **already implemented**, not missing: `POST /api/v1/vendor/home/assign-driver` (`Modules/Vendor/app/Http/Controllers/Api/V1/HomeController.php::assignDriver()`) lets a vendor assign or re-assign the pickup/delivery driver in one call — it frees the previous driver (`is_available = true`) and overwrites `driver_id` / `pickup_driver_id` / `delivery_driver_id` on the order. No separate "cancel driver" step is needed; picking a different driver *is* the cancel+reassign action.

Two gaps were found and fixed:

### Gap A — No block on reassigning after the pickup/delivery already happened

`OrderStatusService::assignPickupDriver()` / `assignDeliveryDriver()` overwrote the driver columns unconditionally, even when the order was already past `PICKED_UP` (pickup already physically done) or past delivery. `App\Enums\OrderStatus` already had `vendorPickupDriverAssignableStatuses()` / `vendorDeliveryDriverAssignableStatuses()` helpers documenting the intended valid window, and `driver.driver_assign_error_pickup_status` / `driver_assign_error_delivery_status` translation strings already existed for this — both were unused until now.

**Fix:** both methods now reject the reassignment (`LogicException`) if the order's current status isn't in the corresponding "assignable" list.

### Gap B — The outgoing driver was never notified

When a driver was swapped out, only the *new* driver got a push notification. The driver being removed just silently stopped seeing the order in their list (via the `driver_id` scopes), with no explicit notice.

**Fix:**
- `App\Events\DriverAssigned` now carries an optional `previousDriverId`.
- `OrderStatusService` passes the outgoing driver's id when reassigning.
- `App\Listeners\SendDriverAssignmentNotification` now sends a "you've been removed from order #X" push to the outgoing driver when `previousDriverId` differs from the new driver.
- Also fixed the `$isReassignment` flag (previously it checked `order->status`, which was also true on a *first* assignment — it now correctly checks whether there *was* a previous driver).

### Affected files

| File | Change |
|------|--------|
| `app/Services/OrderStatusService.php` | Status guard on `assignPickupDriver()` / `assignDeliveryDriver()`; pass `previousDriverId` to the event |
| `app/Events/DriverAssigned.php` | Added `previousDriverId` property |
| `app/Listeners/SendDriverAssignmentNotification.php` | Notify outgoing driver; fixed reassignment detection |

### How to verify

1. Assign a pickup driver to an order (`POST /vendor/home/assign-driver`), have the driver accept it.
2. Reassign to a different driver via the same endpoint — confirm: order now shows the new driver, old driver receives a "removed from order" push, new driver receives the usual assignment push.
3. Advance the order to `picked_up`, then try to reassign the pickup driver again — expect a `400` error (`driver.driver_assign_error_pickup_status`).

---

## Fix 3 (QA #5) — Order editable only until the driver accepts

### Cause

`OrderTrackingController::updateOrder()` (`Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php`) only allowed edits while `status` was `pending` or `branch_review` — i.e. edits were locked out as soon as the order was confirmed, **long before** a driver is even assigned (`driver_pickup_assigned`), let alone before one accepts (`driver_pickup_accepted`). This was stricter than intended, not "not gated at all."

### Fix

- Added `OrderStatus::clientEditableStatusValues()` / `isClientEditable()` (`app/Enums/OrderStatus.php`) covering every status up to and including `driver_pickup_assigned` (driver assigned but not yet accepted): `pending`, `branch_review`, `confirmed`, `waiting_payment`, `payment_confirmed`, `awaiting_remaining_payment`, `driver_pickup_assigned`.
- `updateOrder()` now uses `OrderStatus::isClientEditable($order->status)` instead of the old two-status allow-list — edits are now blocked starting at `driver_pickup_accepted`, matching "editable until the driver accepts."
- Updated the `order.order_can_only_update_pending` translation (en/ar) to reflect the new rule.

### Affected files

| File | Change |
|------|--------|
| `app/Enums/OrderStatus.php` | New `clientEditableStatusValues()` / `isClientEditable()` |
| `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php` | Guard now uses `isClientEditable()` |
| `resources/lang/en/order.php`, `resources/lang/ar/order.php` | Updated error message |

### How to verify

1. Create an order, move it to `confirmed` / `waiting_payment` / `payment_confirmed` — `PUT /api/v1/user/orders/{id}/update` should now succeed (previously returned 400).
2. Assign a pickup driver (`driver_pickup_assigned`) — update should still succeed.
3. Have the driver accept (`driver_pickup_accepted`) — update should now return 400 with the new message.
