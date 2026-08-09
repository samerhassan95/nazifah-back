# Driver API Update: `on_way_to_pickup` Requires Client Visit Confirmation

## Overview
Starting from this update, a driver **cannot** change an order's status to `on_way_to_pickup` until the client has explicitly confirmed/approved the pickup visit.

---

## Affected Endpoint

**Endpoint:** `PUT /api/v1/driver/orders/{order_id}/status`  
**Headers:** `Authorization: Bearer <DRIVER_TOKEN>`  
**Body:**
```json
{
  "status": "on_way_to_pickup"
}
```

---

## Rule & Validation Behavior

1. **Pre-condition:** The order must have been confirmed by the client (`client_pickup_visit_confirmed_at !== null`).
2. **If Client HAS NOT confirmed the pickup visit yet:**
   - **HTTP Status:** `400 Bad Request`
   - **Response Payload:**
     ```json
     {
       "success": false,
       "message": "لا يمكن تغيير حالة الطلب إلى في الطريق حتى يقبل/يؤكد العميل موعد الاستلام أولاً",
       "data": null
     }
     ```
     *(In English locale)*: `"The client must confirm the pickup visit before you can mark the order as on the way"`

3. **If Client HAS confirmed the pickup visit:**
   - Status updates successfully to `on_way_to_pickup`.

---

## Order Pickup Flow Sequence (Flutter Integration)

```
[ Driver Assigned / Accepted ]
           │
           ▼
[ Driver Notifies Client / Order Pending Visit Confirmation ]
           │
           ▼
[Client Confirms Visit] ──► (POST /api/v1/user/orders/{order_id}/visit-response {"action": "confirm"})
           │
           ▼
[ Driver Updates Status ] ──► (PUT /api/v1/driver/orders/{order_id}/status {"status": "on_way_to_pickup"})
```

---

## Action Items for Flutter App
- **Driver App:** Handle the `400 Bad Request` error gracefully if a driver attempts to start the trip before the client accepts/confirms the visit prompt.
- **Client App:** Ensure the client is prompted to confirm the pickup visit when the driver is assigned / notifies them.
