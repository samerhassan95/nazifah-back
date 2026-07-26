# Order Fixes – Invoice Totals & Multiple Main Services

**Date:** 2026-07-22  
**Repo:** `samerhassan95/nazifah-back`  
**Deployed:** `https://back.nathefah.com`

---

## Summary

Two production issues from the Nazefah QA note were fixed:

1. **Extra / incorrect amounts on the invoice after editing an order**
2. **Unable to select more than one main service for the same piece**

No new endpoints were added. Existing order endpoints now behave correctly and accept an optional `service_ids` array.

---

## Bug 1 – Inflated invoice after edit

### Cause
- **Create** saved each quantity unit as a separate row (`quantity = 1`).
- **Update** saved bundled quantity on one row and also stored addition prices inside `unit_price` **and** as separate addition rows.
- Invoice / edit responses could then double-count additions or multiply quantities incorrectly.

### Fix
- `replaceOrderItems` now splits quantity into `quantity = 1` rows (same as create).
- Effective pricing and update response use:

```text
(piece_price + service_price) × quantity + additional_services_total
```

- Vendor review “modified” path no longer double-adds additional services.

### Affected endpoints

| Method | Endpoint |
|--------|----------|
| `PUT` | `/api/v1/user/orders/{order_id}/update` |
| `POST` | `/api/v1/vendor/orders/{orderId}/review` |

### How to verify
1. Create a new order → check invoice total (correct).
2. Open the same order → Edit → go to invoice **without adding items**.
3. Total must stay the same (no extra amounts).

---

## Bug 2 – Multiple main services per piece

### Cause
Each order line allowed only one `service_id`. Catalog already supports multiple main services per piece (e.g. wash + iron), but the order API did not.

### Fix
Client may send either:
- `service_id` (single – unchanged), or
- `service_ids` (array – expanded server-side into one line per main service)

### Affected endpoints

| Method | Endpoint |
|--------|----------|
| `POST` | `/api/v1/user/orders` |
| `POST` | `/api/v1/user/orders/calculate` |
| `POST` | `/api/v1/user/orders/validate-coupon` |
| `PUT` | `/api/v1/user/orders/{order_id}/update` |
| `POST` | `/api/v1/vendor/orders/calculate` |

### Example payload

**Before (one main service):**
```json
{
  "piece_id": 1,
  "quantity": 1,
  "service_id": 10,
  "additional_service_ids": [100]
}
```

**After (multiple main services on same piece):**
```json
{
  "piece_id": 1,
  "quantity": 1,
  "service_ids": [10, 20],
  "additional_service_ids": [100]
}
```

Server expands this to two order lines:
- piece `1` + service `10`
- piece `1` + service `20`

Sending two separate items with the same `piece_id` and different `service_id` values also works.

### How to verify
1. Create a new order.
2. Select a piece (e.g. shirt / suit).
3. Select more than one main service (e.g. wash + iron).
4. Calculate / store succeeds; invoice shows both services.

> **Mobile note:** App UI must send `service_ids` (or multiple lines). Backend is ready.

---

## Code touchpoints

| Area | File |
|------|------|
| Item normalizer (`service_ids`) | `Modules/Order/app/Support/OrderItemsNormalizer.php` |
| Replace items on edit | `Modules/Order/app/Services/OrderPaymentService.php` |
| Effective line pricing | `Modules/Order/app/Models/Order.php` |
| User create / calculate / coupon | `Modules/Order/app/Http/Controllers/Api/V1/User/OrderController.php` |
| User update order | `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php` |
| Vendor calculate | `Modules/Vendor/app/Http/Controllers/Api/V1/OrderController.php` |
| Vendor review totals | `app/Services/VendorOrderReviewService.php` |

---

## Mobile / frontend checklist

- [ ] Use `service_ids` when user picks multiple main services for one piece.
- [ ] Keep using `service_id` for single selection (backward compatible).
- [ ] After edit, rely on order `final_amount` / recalculated line totals; do **not** sum `unit_price` + `additional_services` again if `unit_price` is already inclusive in older responses.
- [ ] Re-test create → edit → invoice with quantity &gt; 1 and with additional services.

---

## Deploy note

Deployed to production from `nazifah-back` (`main`).  
`.env` on server was preserved; `view:cache` optional for this API backend.
