# Home Order Action Card — API Spec

> For Flutter: show [`OrderConfirmationSection`](../lib/feature/orders/view/presentation/widgets/order_confirmation_section.dart) on Home when the user has a pending client action.

## Goal

When the user opens **Home**, the app calls a single API (no `order_id` required upfront).

- If there is **no** pending action → empty list → hide the card.
- If there **is** a pending action → response includes `order_id` + full action payload → render the same confirmation UI used on order tracking.

```
Home open
  → GET orders/order-action
  → parse OnTheWayOrdersModel / ProcedureData
  → filter hasClientAction
  → show OrderConfirmationSection / ProceduresItem
```

---

## Endpoint

```
GET /api/v1/user/orders/order-action
```

**Auth:** `Bearer <client_token>` (logged-in users only; skip for guests)

**Query:** none required

**Body:** none

> Path name can be adjusted (`orders/actions-required`, `home/order-action`, etc.) as long as the response body matches below.

---

## Response (success — has actions)

Reuse the **same envelope** as `orders/on-the-way/{id}` so Flutter can parse with existing `OnTheWayOrdersModel` / `ProcedureData`.

```json
{
  "status": true,
  "code": 200,
  "message": "OK",
  "data": {
    "orders": [
      {
        "order_id": 123,
        "status": "on_way_to_pickup",
        "title": "Driver is on the way",
        "message": "Please confirm you are ready for pickup",
        "time_remaining": null,
        "waiting_for": "client",
        "requires_visit_response": true,
        "requires_handoff_confirmation": false,
        "available_actions": {
          "confirm": true,
          "postpone": true,
          "confirm_handoff": false,
          "confirm_label": null,
          "handoff_type": null,
          "direction": null,
          "endpoint": null,
          "confirm_action": null
        },
        "visit": {
          "confirm_label": "Accept",
          "postpone_label": "Postpone"
        },
        "handoff": null,
        "accepted_items": [],
        "rejected_items": [],
        "modified_items": []
      }
    ],
    "total": 1
  }
}
```

## Response (success — no actions)

```json
{
  "status": true,
  "code": 200,
  "message": "OK",
  "data": {
    "orders": [],
    "total": 0
  }
}
```

---

## What each order item must include

| Field | Required | Notes |
|-------|----------|--------|
| `order_id` | **Yes** | Used for confirm/reject/handoff APIs and navigation to tracking |
| `status` | **Yes** | e.g. `branch_review`, `on_way_to_pickup`, `waiting_client_receipt`, … |
| `title` / `message` | Recommended | Card title & description (`status_label` / `action_description` also accepted) |
| `requires_visit_response` | When visit | + `available_actions.confirm` / `postpone` |
| `requires_handoff_confirmation` | When handoff | + `available_actions.confirm_handoff` or `handoff` object |
| `visit` | When visit | `confirm_label`, `postpone_label` |
| `handoff` | When handoff | `type`, `confirm_label`, … |
| `accepted_items` / `rejected_items` / `modified_items` | When `branch_review` | Same as pending-approval payload |
| `available_actions` | When actionable | Drives which buttons show |

### Client action detection (`ProcedureData.hasClientAction`)

An item is shown on Home if **any** of these is true:

1. **Branch review** — `status == branch_review` OR accepted/rejected items present  
2. **Handoff** — `requires_handoff_confirmation == true` AND (`confirm_handoff` or `handoff` present)  
3. **Visit** — `requires_visit_response == true` AND confirm/postpone available (handoff wins if both)  
4. **Receipt** — `message` contains `receipt-status`

Only return orders that need client action (preferred). Empty list if none.

---

## Follow-up action APIs (unchanged)

After the user taps a button on the card, existing endpoints stay the same (use `order_id` from this response):

| UI action | Method | Endpoint |
|-----------|--------|----------|
| Visit accept | `POST` | `/api/v1/user/orders/{order_id}/visit-response` (`action: confirm`) |
| Visit postpone | `POST` | `/api/v1/user/orders/{order_id}/visit-response` (`action: postpone` + reason/time) |
| Receipt accept | `POST` | receipt-status → `receipt_accepted` |
| Receipt reject | `POST` | receipt-status → `receipt_rejected` |
| Handoff confirm | `POST` | `/api/v1/user/orders/{order_id}/confirm-handoff` |

Details: [CLIENT_ORDER_CONFIRMATION_AR.md](./CLIENT_ORDER_CONFIRMATION_AR.md)

After success, Home should **call `GET orders/order-action` again** (card disappears if nothing left).

---

## Flutter integration (planned)

1. Add `EndPoints.orderAction` → `orders/order-action`
2. `OrderDataSource.getOrderActions()` → parse `OnTheWayOrdersModel`
3. On `HomeView` init (logged-in only): fetch + provide `ProceduresCubit` (or home-specific cubit)
4. Insert `OrderConfirmationSection` near top of home (e.g. under address bar)
   - `orderId` from first actionable item (`orders.first.orderId`)
   - `orderStatus` from that item’s `status`
5. `onActionCompleted` → refresh `order-action`
6. Guests: do not call

### Why this response shape?

`OrderConfirmationSection` already builds cards from `ProceduresCubit` → `List<ProcedureData>`. Matching `orders/on-the-way/{id}` avoids a new model and reuses `ProceduresItem` (moving border + shimmer + all actions).

---

## Backend notes

- Return **all** pending client actions across the user’s current orders (not only one order), unless product decides “single most urgent” only (`total: 1`).
- Prefer server-side filtering with the same rules as `hasClientAction`.
- Include `branch_review` pending-approval fields in the same list (or merge pending-approval into this endpoint) so Home does not need a second call.
