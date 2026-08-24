# Order Tracking — New Fields for Flutter

```
GET /api/v1/user/orders/{orderId}/tracking
```

**Auth:** `Bearer <client_token>`

Three groups of fields were added to this endpoint's response. Nothing existing was removed or renamed — this is purely additive, safe to integrate incrementally.

---

## 1. Vendor/Driver Handoff Progress Flags

Lets the tracking screen show live handoff progress (laundry ↔ driver) without polling another endpoint.

| Field                               | Type           | Meaning                                                            |
|--------------------------------------|----------------|----------------------------------------------------------------------|
| `can_confirm_pickup_from_driver`     | boolean        | (Informational) vendor can confirm receiving from the pickup driver |
| `can_confirm_handover_to_delivery`   | boolean        | (Informational) vendor can confirm handing off to the delivery driver |
| `can_mark_on_the_way`                | boolean        | (Informational) delivery driver may start the trip                  |
| `vendor_handed_to_delivery_at`       | string \| null | ISO timestamp — when the vendor handed the order to the delivery driver |

These are vendor/driver-side actions — the client app doesn't act on them directly, but they're useful for showing an accurate live status/progress indicator (e.g. "your order has been handed to the delivery driver").

---

## 2. Driver Rejection History

If a driver ever declines a pickup or delivery assignment, the order reverts and the vendor assigns someone else. This is now visible to the client.

| Field                   | Type    | Description                                                                 |
|-------------------------|---------|----------------------------------------------------------------------------|
| `had_driver_rejection`  | boolean | `true` **only** while the order is currently sitting at `confirmed` or `delivered_to_branch` **and** has at least one rejection on record. Goes back to `false` once a new driver accepts and the order moves forward — use it to show a transient "looking for another driver" banner, not a permanent badge. |
| `driver_rejections`     | array   | Full history, oldest first. Doesn't disappear when `had_driver_rejection` goes false — use this if you want a persistent log/timeline entry instead of a banner. |

Each entry in `driver_rejections`:

```json
{
  "trip_type": "pickup",
  "driver": {
    "id": 12,
    "name": "Ahmed",
    "phone": "+9665XXXXXXXX",
    "image": null,
    "latitude": 24.1,
    "longitude": 46.2,
    "rating": 4.8
  },
  "reason": "Too far from my location",
  "rejected_at": "2026-08-24T10:15:00.000000Z"
}
```

- `trip_type` — `pickup` or `delivery`.
- `reason` — nullable; the driver can reject without giving one. If null, show a generic "driver declined" message instead of leaving a blank.
- `driver` — same shape as `delivery_data.pickup_driver` / `delivery_data.delivery_driver` elsewhere in this response.

**Suggested UI:** when `had_driver_rejection` is `true`, show a short banner like "A driver declined your order — we're assigning another one now." Use `driver_rejections` (last entry) if you want to name the trip leg (pickup/delivery) affected.

---

## 3. Branch Walk-in Handoff (Client Drop-off / Pickup)

For orders where the client drops off **and** picks up in person at the branch (`pickup_at_vendor: true`, `delivery_at_vendor: true` — no drivers involved at all), the app needs to prompt the client at two points: when to drop clothes off, and when to come collect them. This already existed as an action elsewhere in the app; it's now visible directly from tracking.

| Field                            | Type            | Description                                      |
|-----------------------------------|-----------------|----------------------------------------------------|
| `requires_handoff_confirmation`   | boolean         | Show a confirm button on the tracking screen       |
| `handoff`                         | object \| null  | Details for the button (see below)                 |

`handoff` object shape:

```json
{
  "type": "give_to_laundry",
  "direction": "give",
  "confirm_label": "Confirm you handed your clothes to the laundry",
  "endpoint": "/api/v1/user/orders/449/confirm-handoff",
  "confirm_action": "confirm"
}
```

`type` is one of:

| `type`                | `direction` | When it appears                                                          | What happens on confirm |
|------------------------|-------------|-----------------------------------------------------------------------------|--------------------------|
| `give_to_laundry`      | `give`      | Walk-in drop-off: order status is `confirmed` and client hasn't confirmed yet | Marks drop-off done, status unchanged |
| `receive_from_laundry` | `receive`   | Walk-in pickup: vendor finished and order is `completed`                    | Status → `delivered`     |
| `give_to_driver`       | `give`      | Home pickup: driver already marked `picked_up`, client confirms handoff     | Marks handoff done       |
| `receive_from_driver`  | `receive`   | Home delivery: driver arrived (`waiting_client_receipt`)                    | Status → `delivered`     |

**To act on it:** `POST` the `endpoint` value with body `{}` (Bearer client token). No extra fields needed.

**Note on `give_to_laundry` specifically:** it only appears while status is exactly `confirmed`. If the order was paid up front and status is `payment_confirmed` instead, this endpoint will **not** show the drop-off prompt (by design — ask backend if this needs revisiting).

**Suggested UI:** when `requires_handoff_confirmation` is `true`, show `handoff.confirm_label` as a button; tapping it calls `handoff.endpoint`.

---

## Full Example Response (relevant fields only)

```json
{
  "order_id": 449,
  "current_status": "completed",
  "status_label": "Ready for Pickup",
  "pickup_at_vendor": true,
  "delivery_at_vendor": true,

  "can_confirm_pickup_from_driver": false,
  "can_confirm_handover_to_delivery": false,
  "can_mark_on_the_way": false,
  "vendor_handed_to_delivery_at": null,

  "had_driver_rejection": false,
  "driver_rejections": [],

  "requires_handoff_confirmation": true,
  "handoff": {
    "type": "receive_from_laundry",
    "direction": "receive",
    "confirm_label": "Confirm you received your clothes from the laundry",
    "endpoint": "/api/v1/user/orders/449/confirm-handoff",
    "confirm_action": "confirm"
  }
}
```

---

---

## 4. Bug Fix: Walk-in Handoff Steps Now Actually Enforced

Not a tracking-endpoint field — a backend fix, but it changes what the app should expect when driving the walk-in flow (§3 above). Flagging it here so client and vendor app behavior matches the backend.

**Before:** on `PUT /api/v1/vendor/orders/{id}/status`, if the vendor tried to mark `delivered_to_branch` or `completed` before the required precondition was met, the backend silently let the status move forward anyway — the client's drop-off confirmation and the "ready for pickup" timestamp could be skipped entirely.

**Now:** those two transitions are rejected with a `400` if their precondition isn't met:

| Target status      | Precondition (must be true first)                                             | Error if not met (`400`)                                          |
|----------------------|---------------------------------------------------------------------------------|----------------------------------------------------------------------|
| `delivered_to_branch` | Client already confirmed drop-off (`client_pickup_handoff_at` set via `give_to_laundry` handoff) — only enforced when `pickup_at_vendor` is `true` | `"Order is not awaiting vendor pickup receipt confirmation"`         |
| `completed`           | Order is currently at `delivered_to_branch` and not already handed off — only enforced when `delivery_at_vendor` is `true` | `"Order is not ready to be marked completed for branch pickup"`      |

**What this means for the client app:** the `give_to_laundry` handoff confirmation (§3) is no longer optional/cosmetic — the vendor genuinely cannot move the order forward until the client taps confirm. Make sure the drop-off confirm button isn't skippable/dismissible in a way that leaves the client unable to explain why their order is stuck at `confirmed`.

**What this means for the vendor app:** if it calls `PUT /vendor/orders/{id}/status` with `delivered_to_branch` or `completed` before the client/previous step has happened, it will now get a `400` with the message above instead of silently succeeding — handle that error case (e.g. show "waiting on customer drop-off confirmation").

---

## Nothing to migrate on the app side

- No breaking changes — all fields are new additions to the existing tracking response.
- `driver_rejections` will simply be an empty array `[]` for orders with no rejections.
- `handoff` will be `null` and `requires_handoff_confirmation` will be `false` when there's nothing for the client to confirm right now.
