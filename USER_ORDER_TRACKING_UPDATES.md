# User Order Tracking — Handoff Flags & Driver Rejection History

Two additions to:

```
GET /api/v1/user/orders/{orderId}/tracking
```

---

## 1. Vendor/Driver Handoff Flags

The tracking response now returns the same handoff flags already exposed on the vendor and driver order endpoints, so the client app can show live handoff progress without polling a separate endpoint.

| Field                               | Type          | Meaning                                                        |
|--------------------------------------|---------------|-----------------------------------------------------------------|
| `can_confirm_pickup_from_driver`     | boolean       | Vendor can now confirm receiving the order from the pickup driver |
| `can_confirm_handover_to_delivery`   | boolean       | Vendor can now confirm handing the order to the delivery driver   |
| `can_mark_on_the_way`                | boolean       | Delivery driver may mark the order `on_way_to_delivery`          |
| `vendor_handed_to_delivery_at`       | string \| null| ISO timestamp of when the vendor handed the order to the delivery driver |

Implementation: `OrderTrackingController::getTracking()` merges `VendorOrderHandoffService::vendorConfirmFlags()` and `driverDeliveryActionFlags()` into the response — the same helpers the vendor/driver controllers already use, so the three endpoints stay in sync automatically.

Full flag lifecycle and semantics: see [VENDOR_CONFIRM_HANDOFF.md](VENDOR_CONFIRM_HANDOFF.md).

---

## 2. Driver Rejection History

When a driver rejects a pickup or delivery assignment (`POST /api/v1/driver/orders/{id}/reject`), the order is unassigned and reverted so the vendor can assign someone else — but until now nothing recorded *that it happened*. Clients had no way to know a driver had declined their order.

### New table: `order_driver_rejections`

| Column       | Type      | Notes                          |
|--------------|-----------|---------------------------------|
| `order_id`   | FK        | → `orders`                     |
| `driver_id`  | FK        | → `drivers`                    |
| `trip_type`  | string(20)| `pickup` \| `delivery`         |
| `reason`     | string, nullable | Optional reason the driver gave |
| `rejected_at`| timestamp | When the rejection happened    |

A row is inserted every time `OrderStatusService::handleDriverResponse()` processes a `reject` — one order can accumulate multiple rows across reassignments (e.g. two different pickup drivers reject before one accepts).

### New tracking response fields

| Field                  | Type    | Description                                                             |
|-------------------------|---------|---------------------------------------------------------------------------|
| `had_driver_rejection`  | boolean | `true` only while status is `confirmed` or `delivered_to_branch` **and** the order has at least one recorded rejection |
| `driver_rejections`     | array   | Full history, oldest first — not status-gated                            |

`confirmed` is what a pickup-driver rejection reverts the order to; `delivered_to_branch` is what a delivery-driver rejection reverts it to — both mean "waiting for the vendor to assign another driver." Once the order moves past that status, `had_driver_rejection` goes back to `false` even though `driver_rejections` keeps the full history. It never reads `true` just because the order is naturally sitting at one of those two statuses with no rejection on record.

```json
{
  "had_driver_rejection": true,
  "driver_rejections": [
    {
      "trip_type": "pickup",
      "driver": {
        "id": 12,
        "name": "Ahmed",
        "phone": "+9665...",
        "image": null,
        "latitude": 24.1,
        "longitude": 46.2,
        "rating": 4.8
      },
      "reason": "Too far from my location",
      "rejected_at": "2026-08-24T10:15:00.000000Z"
    }
  ]
}
```

`reason` is nullable — a driver can reject without giving one.

---

---

## 3. Client Handoff Visibility (Walk-in Drop-off / Pickup)

For orders where `pickup_at_vendor` and `delivery_at_vendor` are both `true` (client drops off and picks up in person at the branch — no drivers involved), the drop-off/pickup steps already existed via `POST /api/v1/user/orders/{orderId}/confirm-handoff` (see [CLIENT_ORDER_CONFIRMATION.md](CLIENT_ORDER_CONFIRMATION.md)), but weren't visible from the tracking screen. The tracking endpoint now surfaces them.

| Field                            | Type          | Description                                                    |
|-----------------------------------|---------------|------------------------------------------------------------------|
| `requires_handoff_confirmation`   | boolean       | Show the client a confirm-handoff button                        |
| `handoff`                         | object \| null| `{ type, direction, confirm_label, endpoint, confirm_action }`  |

Reuses `ClientOrderHandoffService::resolveHandoffContext()` (single source of truth for handoff type/label), covering:

- `give_to_laundry` — client drops clothes off at the branch. **On this endpoint only**, this is surfaced at status `confirmed` — not `payment_confirmed` (a deliberate simplification for the tracking screen; other endpoints using the shared service still honor both).
- `receive_from_laundry` — order is `completed` (vendor marked it ready via `requestClientDelivery()`, which sets `vendor_delivery_ready_at`); client confirms pickup, status moves to `delivered`.
- `give_to_driver` / `receive_from_driver` — the home pickup/delivery equivalents, unchanged.

Full walk-in flow (`pickup_at_vendor` + `delivery_at_vendor` both `true`):

```
confirmed
  → [CLIENT confirm-handoff: give_to_laundry] → client_pickup_handoff_at set (status unchanged)
  → [VENDOR marks pickup received] → delivered_to_branch
  → [VENDOR requestClientDelivery()] → completed, vendor_delivery_ready_at set
  → [CLIENT confirm-handoff: receive_from_laundry] → delivered
```

---

## Files Touched

- `Modules/Order/database/migrations/2026_08_24_000001_create_order_driver_rejections_table.php` — new table
- `Modules/Order/app/Models/OrderDriverRejection.php` — new model
- `Modules/Order/app/Models/Order.php` — added `driverRejections()` relation
- `app/Services/OrderStatusService.php` — logs a rejection row in `handleDriverResponse()`
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php` — eager-loads rejections + handoff flags, adds them to the response, plus client handoff visibility (§3)
- `VENDOR_CONFIRM_HANDOFF.md` — updated flag table + new "Driver Rejection Visibility" section

## Deployment

Requires a migration on any environment running this code:

```bash
php artisan migrate
php artisan optimize:clear
```

(Already applied on `back.nathefah.com` as of 2026-08-24.)
