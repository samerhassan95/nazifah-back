# Vendor Confirm Handoff — API Guide

## Overview

Two order handoff confirmations have moved from the **driver** to the **vendor (laundry)**:

1. **Pickup handoff** — Laundry confirms receiving the order from the pickup driver.
2. **Delivery handoff** — Laundry confirms handing the order to the delivery driver.

A single new endpoint handles both cases. The system determines which action to perform based on the current order status.

---

## New Endpoint

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
  "notes": "تم استلام الطلب من المندوب"
}
```

### Response

```json
{
  "status": true,
  "code": 200,
  "message": "Laundry confirmed receiving the order from the pickup driver",
  "data": {
    "id": 334,
    "status": "delivered_to_branch",
    "status_label": "Delivered to Branch",
    "pickup_at_vendor": false,
    "delivery_at_vendor": false,
    "requires_handoff_action": true,
    "available_handoff_actions": [],
    "can_confirm_pickup_from_driver": false,
    "can_confirm_handover_to_delivery": false,
    "vendor_pickup_received_at": null,
    "vendor_delivery_ready_at": null,
    "vendor_client_delivery_handoff_at": null
  }
}
```

---

## New Response Flags

Two new boolean flags are now returned in **both** `GET /api/v1/vendor/orders/{id}` and `GET /api/v1/driver/orders/{id}`:

| Flag                               | Meaning                                                        |
|------------------------------------|-----------------------------------------------------------------|
| `can_confirm_pickup_from_driver`   | Vendor can confirm receiving the order from the pickup driver   |
| `can_confirm_handover_to_delivery` | Vendor can confirm handing the order to the delivery driver     |

Use these flags to show/hide a confirmation button in the app UI.

---

## Flow

### Case 1: Pickup Driver → Laundry

```
Status: picked_up
  ↓
  can_confirm_pickup_from_driver = true
  ↓
  Vendor calls: POST /vendor/orders/{id}/confirm-handoff
  ↓
Status: delivered_to_branch
  ↓
  can_confirm_pickup_from_driver = false
```

**When does `can_confirm_pickup_from_driver` become `true`?**

- The order needs a pickup driver (not `pickup_at_vendor`)
- A `pickup_driver_id` is assigned
- Current status is `picked_up`

**What happens after confirm?**

- Status changes to `delivered_to_branch`
- The flag resets to `false`
- Next step: assign a delivery driver (if needed)

---

### Case 2: Laundry → Delivery Driver

```
Status: driver_delivery_accepted
  ↓
  can_confirm_handover_to_delivery = true
  ↓
  Vendor calls: POST /vendor/orders/{id}/confirm-handoff
  ↓
Status: on_way_to_delivery
  ↓
  can_confirm_handover_to_delivery = false
```

**When does `can_confirm_handover_to_delivery` become `true`?**

- The order needs a delivery driver (not `delivery_at_vendor`)
- A `delivery_driver_id` is assigned
- Current status is `driver_delivery_accepted`

**What happens after confirm?**

- Status changes to `on_way_to_delivery`
- The flag resets to `false`
- Delivery driver is now officially on the way to the client

---

## Full Order Lifecycle (with vendor confirms)

```
pending
  → confirmed
  → waiting_payment / payment_confirmed
  → driver_pickup_assigned
  → driver_pickup_accepted
  → on_way_to_pickup
  → picked_up
  ──────────────────────────────────────────────
  → [VENDOR confirms pickup from driver]        ← NEW
  ──────────────────────────────────────────────
  → delivered_to_branch
  → driver_delivery_assigned
  → driver_delivery_accepted
  ──────────────────────────────────────────────
  → [VENDOR confirms handover to delivery]      ← NEW
  ──────────────────────────────────────────────
  → on_way_to_delivery
  → waiting_client_receipt
  → delivered
  → completed
```

---

## Driver `confirm-qr` Change

`POST /api/v1/driver/orders/{id}/confirm-qr` **no longer** transitions these two cases:

| Driver Status              | Old Behavior                    | New Behavior                                         |
|----------------------------|---------------------------------|------------------------------------------------------|
| `picked_up` (pickup)       | → `delivered_to_branch`         | Returns error: "Confirmation is now performed by the laundry" |
| `driver_delivery_accepted` | → `on_way_to_delivery`          | Returns error: "Confirmation is now performed by the laundry" |

The driver app should hide the QR confirm button for these two statuses and instead show a message that the laundry will confirm.

---

## Where Flags Appear

| Endpoint                            | `can_confirm_pickup_from_driver` | `can_confirm_handover_to_delivery` |
|-------------------------------------|----------------------------------|------------------------------------|
| `GET /api/v1/vendor/orders/{id}`    | ✓                                | ✓                                  |
| `GET /api/v1/driver/orders/{id}`    | ✓                                | ✓                                  |
| `POST /vendor/orders/{id}/confirm-handoff` (response) | ✓                   | ✓                                  |
| `PUT /vendor/orders/{id}/status` (response)           | ✓                   | ✓                                  |

---

## Error Responses

| Scenario                                        | HTTP | Message (EN)                                                |
|-------------------------------------------------|------|-------------------------------------------------------------|
| Order not found or wrong vendor                 | 404  | Order not found                                             |
| No handoff action available for current status  | 400  | Handoff action is not available for this order in its current state |
| Invalid status transition                       | 400  | (Specific transition error message)                         |

---

## Quick Test

```bash
# 1. Check order
GET /api/v1/vendor/orders/{id}
→ Look at: status, can_confirm_pickup_from_driver, can_confirm_handover_to_delivery

# 2. If either flag is true, call:
POST /api/v1/vendor/orders/{id}/confirm-handoff
Body: {} or {"notes": "..."}

# 3. Check order again
GET /api/v1/vendor/orders/{id}
→ Status should have changed, flag should be false
```

---

## Summary for Flutter Implementation

### Vendor App
- On the order details screen, read `can_confirm_pickup_from_driver` and `can_confirm_handover_to_delivery`.
- If either is `true`, show a confirmation button with the appropriate label:
  - `can_confirm_pickup_from_driver`: "تأكيد استلام الطلب من المندوب" / "Confirm order received from driver"
  - `can_confirm_handover_to_delivery`: "تأكيد تسليم الطلب للمندوب" / "Confirm order handed to driver"
- On tap, call `POST /vendor/orders/{id}/confirm-handoff`.
- Refresh the order details after a successful response.

### Driver App
- On `picked_up` and `driver_delivery_accepted` statuses, **do not** show the QR confirm button.
- Optionally show an info message: "التأكيد يتم من جهة المغسلة" / "Confirmation is performed by the laundry".
- The flags `can_confirm_pickup_from_driver` / `can_confirm_handover_to_delivery` are available in the driver order response for reference if needed.
