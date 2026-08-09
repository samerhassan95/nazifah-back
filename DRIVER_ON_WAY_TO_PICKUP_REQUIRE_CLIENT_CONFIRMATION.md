# Order Pickup Visit Confirmation Flow

## Correct Workflow Sequence

1. **Driver Starts Trip (`on_way_to_pickup`)**
   - The driver updates order status to `on_way_to_pickup`:
     - **Endpoint:** `PUT /api/v1/driver/orders/{order_id}/status`
     - **Body:** `{"status": "on_way_to_pickup"}`

2. **Client Receives Prompt (`requires_visit_response: true`)**
   - Once status is `on_way_to_pickup`, the client app detects `requires_visit_response: true` and displays the visit response card.

3. **Client Responds (`visit-response`)**
   - **Endpoint:** `POST /api/v1/user/orders/{order_id}/visit-response`
   - **Options:**
     - **Confirm (تأكيد):**
       ```json
       {
         "action": "confirm"
       }
       ```
       *Result:* Timestamp `client_pickup_visit_confirmed_at` is saved, driver proceeds to pickup clothes.

     - **Postpone (تأجيل):**
       ```json
       {
         "action": "postpone",
         "reason": "تأجيل الموعد",
         "rescheduled_at": "2026-08-10 16:00:00"
       }
       ```
       *Result:* Order status updates to `client_postponed_pickup`, driver is notified that pickup is postponed.
