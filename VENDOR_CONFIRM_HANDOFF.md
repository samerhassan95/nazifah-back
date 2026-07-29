# Vendor Confirm Handoff — API Guide

## Overview

The handoff flow is now **two-step**:

1. The **driver** confirms QR to enable the appropriate vendor handoff flag.
2. The **vendor (laundry)** confirms the handoff to perform the actual order status transition.

This means:

- `POST /api/v1/driver/orders/{id}/confirm-qr` no longer changes the order status for the two vendor-owned handoff steps.
- `POST /api/v1/vendor/orders/{id}/confirm-handoff` is the endpoint that changes the order status.
- `can_confirm_pickup_from_driver` and `can_confirm_handover_to_delivery` are now **persisted boolean fields on the order**, not just computed from the current status.

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
  "notes": "تم استلام الطلب من المندوب"
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

Two boolean flags are now returned in **both** `GET /api/v1/vendor/orders/{id}` and `GET /api/v1/driver/orders/{id}`:

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
Status: on_way_to_delivery
  ↓
  can_confirm_handover_to_delivery = false
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
  → on_way_to_delivery
  → can_confirm_handover_to_delivery = false
```

---

## Behavior Change Summary

### Old Behavior

| Endpoint | Status Before | Status After |
|----------|---------------|--------------|
| `driver/orders/{id}/confirm-qr` | `picked_up` | `delivered_to_branch` |
| `driver/orders/{id}/confirm-qr` | `driver_delivery_accepted` | `on_way_to_delivery` |

### New Behavior

| Endpoint | Status Before | Status After | What Changes |
|----------|---------------|--------------|--------------|
| `driver/orders/{id}/confirm-qr` | `picked_up` | `picked_up` | Enables `can_confirm_pickup_from_driver=true` |
| `driver/orders/{id}/confirm-qr` | `driver_delivery_accepted` | `driver_delivery_accepted` | Enables `can_confirm_handover_to_delivery=true` |
| `vendor/orders/{id}/confirm-handoff` | `picked_up` | `delivered_to_branch` | Consumes and clears pickup flag |
| `vendor/orders/{id}/confirm-handoff` | `driver_delivery_accepted` | `on_way_to_delivery` | Consumes and clears delivery flag |

---

## Where Flags Appear

| Endpoint | `can_confirm_pickup_from_driver` | `can_confirm_handover_to_delivery` |
|----------|----------------------------------|------------------------------------|
| `GET /api/v1/vendor/orders/{id}` | ✓ | ✓ |
| `GET /api/v1/driver/orders/{id}` | ✓ | ✓ |
| `POST /api/v1/driver/orders/{id}/confirm-qr` response | ✓ | ✓ |
| `POST /api/v1/vendor/orders/{id}/confirm-handoff` response | ✓ | ✓ |
| `PUT /api/v1/vendor/orders/{id}/status` response | ✓ | ✓ |

---

## Validation Rules

### Vendor `confirm-handoff`

Vendor confirm succeeds only when:

- The correct flag is `true`
- The order still has the correct status
  - `picked_up` for pickup handoff
  - `driver_delivery_accepted` for delivery handoff

If the flag is `true` but the status changed, vendor confirm is rejected as a safety check.

---

## Error Responses

| Scenario | HTTP | Message (EN) |
|----------|------|--------------|
| Order not found or wrong vendor | 404 | Order not found |
| No handoff action available for current state | 400 | Handoff action is not available for this order in its current state |
| Invalid status transition | 400 | Specific transition error message |
| Invalid QR | 400 | Invalid QR code |

---

## Quick Test

```bash
# 1. Driver QR
POST /api/v1/driver/orders/{id}/confirm-qr
→ Status stays the same, appropriate flag becomes true

# 2. Check order from driver or vendor side
GET /api/v1/driver/orders/{id}
GET /api/v1/vendor/orders/{id}
→ Look at: status, can_confirm_pickup_from_driver, can_confirm_handover_to_delivery

# 3. Vendor confirms handoff
POST /api/v1/vendor/orders/{id}/confirm-handoff
Body: {} or {"notes": "..."}

# 4. Check order again
GET /api/v1/vendor/orders/{id}
→ Status should have changed, used flag should be false
```

---

## Summary For Flutter Implementation

### Vendor App

- On the order details screen, read:
  - `can_confirm_pickup_from_driver`
  - `can_confirm_handover_to_delivery`
- If either flag is `true`, show the corresponding confirmation button:
  - `can_confirm_pickup_from_driver`: "تأكيد استلام الطلب من المندوب" / "Confirm order received from driver"
  - `can_confirm_handover_to_delivery`: "تأكيد تسليم الطلب للمندوب" / "Confirm order handed to driver"
- On tap, call `POST /vendor/orders/{id}/confirm-handoff`
- Refresh order details after success

### Driver App

- Keep the QR confirm action for `picked_up` and `driver_delivery_accepted`
- After QR success:
  - Do not expect order status to change immediately
  - Expect the correct vendor handoff flag to become `true`
- Refresh the order after QR if the app wants to show the new flag state

### UI Meaning

- QR success means: "driver finished their part, vendor can now confirm"
- Vendor confirm success means: "handoff finalized, order status changed"
