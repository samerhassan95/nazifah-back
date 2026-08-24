# Vendor Confirm Handoff — API Guide

## Overview

Handoff is multi-step and role-specific:

1. The **driver** confirms QR to enable the appropriate vendor handoff flag (status unchanged).
2. The **vendor (laundry)** confirms the handoff:
   - Pickup leg: status → `delivered_to_branch`
   - Delivery leg: status stays `driver_delivery_accepted`, sets `vendor_handed_to_delivery_at`
3. For delivery, the **driver** then marks `on_way_to_delivery` for that specific order.

This means:

- `POST /api/v1/driver/orders/{id}/confirm-qr` does not change status for vendor-owned handoff steps.
- `POST /api/v1/vendor/orders/{id}/confirm-handoff` on delivery does **not** move to `on_way_to_delivery`.
- `can_confirm_pickup_from_driver` and `can_confirm_handover_to_delivery` are persisted boolean fields on the order.
- `can_mark_on_the_way` tells the driver app when laundry handoff is done and they may start the trip.
---

## Vendor Endpoint

```
POST /api/v1/vendor/orders/{orderId}/confirm-handoff
```

**Auth:** `Bearer <vendor_token>`

### Request Body

| Field   | Type   | Required | Description          |
|---------|--------|----------|----------------------|
| `notes` | string | No       | Optional vendor note |

Minimal request:

```json
{}
```

With note:

```json
{
  "notes": "تم استلام الطلب من السائق"
}
```

### Vendor Response Example

```json
{
  "status": true,
  "code": 200,
  "message": "Laundry confirmed receiving the order from the pickup driver",
  "data": {
    "id": 379,
    "status": "delivered_to_branch",
    "status_label": "Delivered to Branch",
    "pickup_at_vendor": false,
    "delivery_at_vendor": false,
    "requires_handoff_action": true,
    "available_handoff_actions": [],
    "can_confirm_pickup_from_driver": false,
    "can_confirm_handover_to_delivery": false
  }
}
```

---

## Driver QR Endpoint

```
POST /api/v1/driver/orders/{orderId}/confirm-qr
```

**Auth:** `Bearer <driver_token>`

### Request Body

| Field     | Type   | Required | Description                 |
|-----------|--------|----------|-----------------------------|
| `qr_code` | string | No       | Optional QR value if needed |

Minimal request:

```json
{}
```

### Driver QR Response Example

```json
{
  "status": true,
  "code": 200,
  "message": "Laundry can now confirm receiving the order from the pickup driver.",
  "data": {
    "order_id": 379,
    "status": "picked_up",
    "status_label": "Picked Up",
    "driver_role": "pickup",
    "can_confirm_pickup_from_driver": true,
    "can_confirm_handover_to_delivery": false
  }
}
```

Important:

- `confirm-qr` **enables a flag**
- It does **not** change the order status for the vendor-owned handoff steps

---

## Persisted Response Flags

Two boolean flags are now returned in `GET /api/v1/vendor/orders/{id}`, `GET /api/v1/driver/orders/{id}`, and `GET /api/v1/user/orders/{id}/tracking`:

| Flag                               | Meaning                                                   |
|------------------------------------|-----------------------------------------------------------|
| `can_confirm_pickup_from_driver`   | Vendor can now confirm receiving the order from pickup driver |
| `can_confirm_handover_to_delivery` | Vendor can now confirm handing the order to delivery driver   |

These are now **stored on the order** and are toggled by the flow itself.

---

## Flow

### Case 1: Pickup Driver → Laundry

```
Status: picked_up
  ↓
  Driver calls: POST /driver/orders/{id}/confirm-qr
  ↓
  status remains: picked_up
  ↓
  can_confirm_pickup_from_driver = true
  ↓
  Vendor calls: POST /vendor/orders/{id}/confirm-handoff
  ↓
Status: delivered_to_branch
  ↓
  can_confirm_pickup_from_driver = false
```

### Case 2: Laundry → Delivery Driver

```
Status: driver_delivery_accepted
  ↓
  Driver calls: POST /driver/orders/{id}/confirm-qr
  ↓
  status remains: driver_delivery_accepted
  ↓
  can_confirm_handover_to_delivery = true
  ↓
  Vendor calls: POST /vendor/orders/{id}/confirm-handoff
  ↓
  status remains: driver_delivery_accepted
  ↓
  vendor_handed_to_delivery_at = now()
  ↓
  can_confirm_handover_to_delivery = false
  ↓
  can_mark_on_the_way = true (for delivery driver)
  ↓
  Driver calls: PUT /driver/orders/{id}/status { "status": "on_way_to_delivery" }
  (or POST /driver/orders/{id}/pickup-complete after handoff)
  ↓
Status: on_way_to_delivery
```

---

## Full Lifecycle For These Steps

```
picked_up
  → [DRIVER confirms QR]
  → can_confirm_pickup_from_driver = true
  → [VENDOR confirms handoff]
  → delivered_to_branch
  → can_confirm_pickup_from_driver = false

driver_delivery_accepted
  → [DRIVER confirms QR]
  → can_confirm_handover_to_delivery = true
  → [VENDOR confirms handoff]
  → status stays driver_delivery_accepted
  → vendor_handed_to_delivery_at set
  → can_confirm_handover_to_delivery = false
  → [DRIVER marks on the way for this order]
  → on_way_to_delivery
```

---

## Behavior Change Summary

### Old Behavior

| Endpoint | Status Before | Status After |
|----------|---------------|--------------|
| `driver/orders/{id}/confirm-qr` | `picked_up` | `delivered_to_branch` |
| `driver/orders/{id}/confirm-qr` | `driver_delivery_accepted` | `on_way_to_delivery` |

### Previous two-step (vendor owned on-the-way)

| Endpoint | Status Before | Status After | What Changes |
|----------|---------------|--------------|--------------|
| `driver/orders/{id}/confirm-qr` | `picked_up` | `picked_up` | Enables `can_confirm_pickup_from_driver=true` |
| `driver/orders/{id}/confirm-qr` | `driver_delivery_accepted` | `driver_delivery_accepted` | Enables `can_confirm_handover_to_delivery=true` |
| `vendor/orders/{id}/confirm-handoff` | `picked_up` | `delivered_to_branch` | Consumes and clears pickup flag |
| `vendor/orders/{id}/confirm-handoff` | `driver_delivery_accepted` | `on_way_to_delivery` | Consumes flag and moved to on the way |

### Current Behavior

| Endpoint | Status Before | Status After | What Changes |
|----------|---------------|--------------|--------------|
| `driver/orders/{id}/confirm-qr` | `picked_up` | `picked_up` | Enables `can_confirm_pickup_from_driver=true` |
| `driver/orders/{id}/confirm-qr` | `driver_delivery_accepted` | `driver_delivery_accepted` | Enables `can_confirm_handover_to_delivery=true` |
| `vendor/orders/{id}/confirm-handoff` | `picked_up` | `delivered_to_branch` | Consumes and clears pickup flag |
| `vendor/orders/{id}/confirm-handoff` | `driver_delivery_accepted` | `driver_delivery_accepted` | Sets `vendor_handed_to_delivery_at`, clears delivery flag |
| `PUT driver/orders/{id}/status` (`on_way_to_delivery`) | `driver_delivery_accepted` | `on_way_to_delivery` | Requires `vendor_handed_to_delivery_at` |
| `POST driver/orders/{id}/pickup-complete` | `driver_delivery_accepted` | `on_way_to_delivery` | Same handoff requirement |

Receiving from laundry and marking on-the-way are separate. A driver can hold multiple `driver_delivery_accepted` orders (same branch) after laundry handoff, then mark on-the-way per order.

---

## Where Flags Appear

| Endpoint | `can_confirm_pickup_from_driver` | `can_confirm_handover_to_delivery` | `can_mark_on_the_way` |
|----------|----------------------------------|-----------------------------------|----------------------|
| `GET /api/v1/vendor/orders/{id}` | ✓ | ✓ | |
| `GET /api/v1/driver/orders/{id}` | ✓ | ✓ | ✓ |
| `GET /api/v1/driver/orders` list items | ✓ | ✓ | ✓ |
| `POST /api/v1/driver/orders/{id}/confirm-qr` response | ✓ | ✓ | ✓ |
| `POST /api/v1/vendor/orders/{id}/confirm-handoff` response | ✓ | ✓ | |
| `PUT /api/v1/vendor/orders/{id}/status` response | ✓ | ✓ | |
| `GET /api/v1/user/orders/{id}/tracking` | ✓ | ✓ | ✓ |

`can_mark_on_the_way` is `true` only when:

- status is `driver_delivery_accepted`
- `vendor_handed_to_delivery_at` is set
- viewer is the delivery driver

---

## Validation Rules

### Vendor `confirm-handoff`

Vendor confirm succeeds only when:

- The correct flag is `true`
- The order still has the correct status
  - `picked_up` for pickup handoff
  - `driver_delivery_accepted` for delivery handoff

If the flag is `true` but the status changed, vendor confirm is rejected as a safety check.

### Driver `on_way_to_delivery`

Succeeds only when laundry already set `vendor_handed_to_delivery_at`. Otherwise returns 400 with message that laundry must confirm handoff first.

---

## Error Responses

| Scenario | HTTP | Message (EN) |
|----------|------|--------------|
| Order not found or wrong vendor | 404 | Order not found |
| No handoff action available for current state | 400 | Handoff action is not available for this order in its current state |
| Invalid status transition | 400 | Specific transition error message |
| On the way before laundry handoff | 400 | Laundry must confirm handing the order to you before you can mark it as on the way |
| Invalid QR | 400 | Invalid QR code |

---

## Quick Test

```bash
# 1. Driver QR (delivery)
POST /api/v1/driver/orders/{id}/confirm-qr
→ Status stays driver_delivery_accepted, can_confirm_handover_to_delivery=true

# 2. Check order
GET /api/v1/driver/orders/{id}
GET /api/v1/vendor/orders/{id}
→ Look at: status, can_confirm_handover_to_delivery, can_mark_on_the_way, vendor_handed_to_delivery_at

# 3. Vendor confirms handoff
POST /api/v1/vendor/orders/{id}/confirm-handoff
Body: {} or {"notes": "..."}
→ Status stays driver_delivery_accepted
→ vendor_handed_to_delivery_at set
→ can_confirm_handover_to_delivery=false
→ can_mark_on_the_way=true on driver side

# 4. Driver marks on the way for this order only
PUT /api/v1/driver/orders/{id}/status
Body: {"status":"on_way_to_delivery"}
→ Status becomes on_way_to_delivery
```

---

## Summary For Flutter Implementation

### Vendor App

- On the order details screen, read:
  - `can_confirm_pickup_from_driver`
  - `can_confirm_handover_to_delivery`
- If either flag is `true`, show the corresponding confirmation button:
  - `can_confirm_pickup_from_driver`: "تأكيد استلام الطلب من السائق" / "Confirm order received from driver"
  - `can_confirm_handover_to_delivery`: "تأكيد تسليم الطلب للسائق" / "Confirm order handed to driver"
- On tap, call `POST /vendor/orders/{id}/confirm-handoff`
- Refresh order details after success
- For delivery handoff success: expect status to stay `driver_delivery_accepted` (not on the way)

### Driver App

- Keep the QR confirm action for `picked_up` and `driver_delivery_accepted`
- After QR success:
  - Do not expect order status to change immediately
  - Expect the correct vendor handoff flag to become `true`
- After vendor delivery handoff: show "في الطريق" when `can_mark_on_the_way=true`
- On tap, call `PUT /driver/orders/{id}/status` with `on_way_to_delivery` for **that order only**
- A driver may hold multiple accepted/handed orders and choose which one to mark on the way

### UI Meaning

- QR success means: "driver finished their part, vendor can now confirm"
- Vendor delivery confirm success means: "bags handed to driver; status still accepted"
- Driver on-the-way success means: "driver started the trip for this order"

---

## Driver Rejection Visibility (User Tracking)

When a driver rejects a pickup or delivery assignment (`POST /api/v1/driver/orders/{id}/reject`), the order is unassigned and its status reverts so the vendor can assign another driver. Every rejection is now persisted in `order_driver_rejections` and surfaced to the client on:

```
GET /api/v1/user/orders/{orderId}/tracking
```

New response fields:

| Field                   | Type    | Description                                                        |
|-------------------------|---------|----------------------------------------------------------------------|
| `had_driver_rejection`  | boolean | `true` only while the order sits at `confirmed` or `delivered_to_branch` **and** has at least one recorded rejection (see below) |
| `driver_rejections`     | array   | Full rejection history, oldest first — not status-gated              |

`had_driver_rejection` is intentionally narrow: `confirmed` is where a pickup rejection reverts the order to, and `delivered_to_branch` is where a delivery rejection reverts it to — both are "waiting for the vendor to assign another driver" states. Once the order moves past that status (a new driver accepted, or it progresses further), the flag goes back to `false` even though `driver_rejections` still has the history. It is **not** `true` just because the order happens to be `confirmed`/`delivered_to_branch` through normal flow with no rejection ever recorded.

Each `driver_rejections` entry:

```json
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
```

`trip_type` is `pickup` or `delivery`. `reason` is nullable (driver may reject without a reason).
