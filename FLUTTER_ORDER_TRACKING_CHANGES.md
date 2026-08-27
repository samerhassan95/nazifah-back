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

## 5. Branch Pickup "Ready" State: `completed` → `waiting_client_receipt`

**This one changes the actual `status` value, not just the label.** Read this section carefully before touching anything that branches on `status === "completed"` for branch-pickup orders.

**The problem:** for `delivery_at_vendor: true` orders (client collects in person from the branch), the vendor's "mark ready" action used to set `status` to `completed` — the *same* value used later for the order's real final closure (after the client has actually collected it). There was no way to tell "ready, still waiting for the client" apart from "actually done" just by looking at `status`. This also meant such orders dropped out of the vendor's "current/in-progress" list the moment they were marked ready, even though the client hadn't shown up yet.

**The fix:** the vendor's "mark ready" action now sets `status` to `waiting_client_receipt` instead (the same status already used for "driver has arrived at your door" on the home-delivery side — reused here for "ready and waiting for you at the branch"). Full corrected flow for `pickup_at_vendor + delivery_at_vendor` both `true`:

```
confirmed
  → [CLIENT confirm-handoff: give_to_laundry] → client_pickup_handoff_at set (status unchanged)
  → [VENDOR confirms pickup received] → delivered_to_branch
  → [VENDOR marks ready] → waiting_client_receipt   ← was "completed" before this fix
  → [CLIENT confirm-handoff: receive_from_laundry] → delivered
  → [later, administrative closure] → completed
```

`completed` now only ever means "fully closed" (reached after `delivered`) — never "ready, not yet collected" — for both branch-pickup and home-delivery orders alike.

**`status_label` wording for `waiting_client_receipt`:** this status was previously only ever set when a delivery driver arrived at the client's home, so its default label text says "At Delivery Location — Waiting for Client." For a branch-pickup order that wording would be wrong (nobody drove anywhere), so the label is now context-aware:

| `status`                | Context                          | `status_label`                                                        |
|--------------------------|-----------------------------------|--------------------------------------------------------------------------|
| `waiting_client_receipt` | `delivery_at_vendor: true`  (branch pickup) | **"Ready for Pickup at Branch"** (ar: "جاهز للاستلام من الفرع")            |
| `waiting_client_receipt` | `delivery_at_vendor: false` (driver delivery) | "At Delivery Location - Waiting for Client" (unchanged)                  |

This applies everywhere `status_label` appears — client, vendor, and driver apps alike, since it's just correcting what the status objectively means, not audience-specific wording.

**Backward compatibility:** any order already sitting at `status: "completed"` (not yet collected) from before this deploy keeps working — every consumer (`confirm-handoff`, the vendor's "confirm client received" action, the on-the-way screen, etc.) still recognizes `completed` as an equivalent "ready" state alongside `waiting_client_receipt`. No data migration was run; old and new orders are both handled. `status_label` also has a narrower backward-compat case for these: `completed` + not yet received still shows "Ready — Awaiting Your Receipt" instead of plain "Completed."

**For the vendor app specifically:** `PUT /vendor/orders/{id}/status` still accepts `{"status": "completed"}` for the "mark ready" action (unchanged, no app update required) — the backend now internally sets the real status to `waiting_client_receipt` regardless of which of the two you send. New builds can send `{"status": "waiting_client_receipt"}` directly if you prefer to match what's actually returned.

**Side effect worth knowing about:** the vendor endpoint used to attach a ZATCA invoice summary (`response.invoice`) at the moment an order was marked ready (since that used to *be* `completed`). Since "ready" is no longer `completed`, the invoice now only attaches at the real final-closure step, not at "ready." This is arguably more correct (invoice reflecting an actually-finished transaction), but flagging it in case any report/dashboard was relying on the old premature timing.

**Not touched:** revenue reports, invoice generation, and admin dashboards that filter on `status === "completed"` elsewhere in the backend were left as-is — they should behave correctly since `completed` still means "fully closed" at the same relative point in the flow, just reached via `waiting_client_receipt → delivered → completed` instead of skipping straight there. If any dashboard counts "completed today" and expects branch-pickup orders that are merely *ready* to be included, that count will now (correctly) exclude them until the client actually collects.

---

## 6. Branch Pickup Needs a Second, Vendor-Side Confirmation to Close

Following on from §5: the client's `POST /user/orders/{id}/confirm-handoff` (`receive_from_laundry`) only moves the order to `delivered` — it does **not** close it out to `completed` by itself. The vendor must send their own confirmation afterward.

Corrected full flow for `pickup_at_vendor + delivery_at_vendor` both `true`:

```
waiting_client_receipt (vendor marked ready)
  → [CLIENT confirm-handoff: receive_from_laundry] → delivered
  → [VENDOR: PUT /vendor/orders/{id}/status  {"status": "completed"}]  ← required, not automatic
  → completed
```

**Vendor app:** once an order is `delivered` (client already confirmed pickup themselves), call `PUT /api/v1/vendor/orders/{id}/status` with `{"status": "completed"}` to send the closing confirmation ("تم الاستلام"). This is the same endpoint/action used to mark an order "ready" earlier in the flow (§5) — the backend now tells the two apart by the order's *current* status (`delivered_to_branch` → marks ready; `delivered` → closes out), so no request-shape change is needed, just call it again at this second point. Returns `400` (`"Order is not awaiting vendor client pickup confirmation"`) if called before the client has confirmed receipt, or if already closed.

**Alternate path (unchanged):** if the client never confirms via the app, the vendor can confirm on the client's behalf earlier — while the order is still `waiting_client_receipt` (or legacy `completed`) — using the same `PUT .../status {"status": "completed"}` call. In that case it goes straight to `delivered` instead (the client-confirmation step is skipped, not the vendor-closing step).

**Client app:** nothing changes here — `receive_from_laundry` still just moves to `delivered`. Don't expect `completed` back from that call; the order will show `delivered` until the vendor sends their closing confirmation.

---

## 7. Push Notification Bug Fix: "Driver Has Arrived" on Orders With No Driver

Backend-only fix, no app changes needed — noted here since it was reported against a real order (walk-in, no driver assigned) that got a push notification saying a driver had arrived.

**Cause:** when an order reaches `waiting_client_receipt`, the notification listener always sent "السائق في موقع التسليم" / "Driver Has Arrived" — written only for the home-delivery case, without checking whether the order actually has a driver. Since §5 now also reaches `waiting_client_receipt` for branch-pickup orders (no driver at all), those clients were getting a nonsensical driver-arrival notification.

**Fix:** the notification now branches on `delivery_at_vendor` — branch-pickup orders get "طلبك جاهز للاستلام" / "Your Order is Ready — pick it up from the branch" instead, matching the `status_label` wording already fixed in §5.

---

## 8. `delivered` Orders Were Disappearing/Miscategorized (Client List + Vendor Tabs)

Backend-only fix. Found while verifying §6 — once a branch-pickup order reaches `delivered` (client confirmed, vendor hasn't closed out yet), three separate places were treating it inconsistently:

- **Client order list** — `GET /user/orders?status=completed` was hardcoded to only match DB status `delivered`, and `status=current` didn't exclude `completed` either (so a fully-closed order could still show up as "current"). **Decided behavior:** `delivered` counts as `current` (client has the order, but the vendor hasn't administratively closed it yet), and `completed` means only the real `completed` status. So: `status=current` now excludes `cancelled` and `completed` (delivered stays in current); `status=completed` matches `completed` only, not `delivered`.
- **Vendor "current" tab** (`Order::scopeVendorCurrent()`) — only kept a branch-pickup order visible while it sat at `completed`-not-yet-collected (the old flow). It didn't account for the new `delivered`-waiting-for-vendor-close state from §6, so those orders could vanish from the vendor's active list before the vendor had done their part.
- **Vendor "completed" tab** (`Order::scopeVendorCompleted()`) — treated *any* `delivered` order as finished, including a branch-pickup order still awaiting the vendor's closing confirmation. Now `delivered` only counts as finished there for home-delivery orders, or once `vendor_client_delivery_handoff_at` is actually set.

No field/response shape changes — this only affects which orders show up under `status=current` / `status=completed` (client) and the vendor's current/completed tabs. If either app caches or locally re-derives "is this order done" instead of trusting these endpoints, this is worth double-checking against the corrected filtering.

---

## 9. Home Delivery Now Closes Straight to `completed` on Client Receipt

**Only affects `delivery_at_vendor: false` orders (driver delivers to the client's home).** Branch pickup (§6) is unchanged — that flow still requires the vendor's separate closing confirmation.

**Before:** the client confirming receipt from the driver (either `POST /user/orders/{id}/confirm-handoff` type `receive_from_driver`, or `POST /user/orders/{id}/confirm-delivery` via QR scan) only moved the order to `delivered`. A *separate* action (`PUT /user/orders/{id}` with `status: receipt_accepted`, the client's "approve receipt" call) was needed afterward to reach `completed`.

**Now:** for home delivery, there's no other party who still needs to act — the driver already delivered, and the client confirming receipt *is* the final step. Both `confirm-handoff` (`receive_from_driver`) and `confirm-delivery` (QR scan) now close the order straight to `completed` in the same call. `client_delivery_handoff_at` is still set as before.

```
waiting_client_receipt (driver arrived)
  → [CLIENT confirm-handoff: receive_from_driver, OR confirm-delivery via QR]
  → completed   ← was "delivered" before this fix; no separate approval call needed anymore
```

**Client app:** expect `status: "completed"` directly back from these two calls now, not `"delivered"`. The separate `receipt_accepted` approval endpoint still exists and still works (harmless no-op if called on an already-`completed` order) but is no longer a required step for home delivery.

**Not affected:** `delivered` is still a real, meaningful intermediate status for branch-pickup orders (§6) — this change is scoped to `delivery_at_vendor: false` only.

---

## 10. `current_driver` is now `null` at `delivered_to_branch`

`delivery_data.current_driver` in the tracking response previously kept showing the pickup driver while the order sat at `delivered_to_branch` — but that driver's leg is already finished at that point, and no delivery driver has started yet, so nobody is actually working the order. `current_driver` is now `null` specifically for that status. `delivery_data.pickup_driver` / `delivery_data.delivery_driver` are unaffected — they still show the respective driver once assigned, regardless of current status.

---

## 11. New `rejected_services` Field — No More Duplicate Item Lines for a Rejected Add-on

**The problem:** when the vendor rejects only an *additional service* (add-on, e.g. "تعليق الملابس"/hanging) on an item while keeping the item's main service(s) accepted, the tracking response showed the item **twice**: once normally in `items` (with only the accepted add-ons), and again as a separate synthetic entry in `rejected_items` — same piece name, just the rejected add-on — which reads as if it were a second, distinct item.

**The fix:** that synthetic duplicate is gone. The item now appears **once**, in `items`, complete — and carries a new field:

```json
{
  "id": 123,
  "ids": [123],
  "piece": { "name": "فستان" },
  "services": [ ... accepted main services ... ],
  "additional_services": [ ... accepted add-ons ... ],
  "rejected_services": [
    { "id": 45, "name": "تعليق الملابس", "price": 1.0 }
  ],
  "status": "accepted"
}
```

`rejected_services` is `[]` when nothing was rejected on that item — always present, never omitted.

**`rejected_items` / `rejected_count` are unaffected in meaning** — they still list only *whole* pieces/services the vendor rejected outright (a full line rejection, not just an add-on). Those didn't change; only the duplicate-add-on-as-fake-item case was removed.

**Scope:** local to `GET /user/orders/{orderId}/tracking` only — `items`/`rejected_items` in that one response. Nothing else (the vendor pending-approval review screen, the `calculate` API, the shared `PendingApprovalItemCategorizer`) was touched.

**Follow-up fix:** the first version of this matched a rejected add-on back to its item by comparing `ids` arrays, which turned out to be unreliable — a categorizer helper (`mergeDuplicateRejectedItems`) collapses separate rejected entries that share the same piece name, which can change the `ids` on the merged entry so it no longer exactly matches any single item. `rejected_services` now reads directly off each order line's own `additionalServicesPivot` rows instead (bypassing that merge entirely), and the `rejected_items` split is now decided by checking actual `vendor_status` on the order's items rather than comparing `ids`. If you tested this earlier and saw `rejected_services` always `[]`, that was the bug — retest against the current deploy.

---

## 12. Conditional `is_free_delivery` Field in `calculate` API Summary

In the `calculate` API response (`POST /api/v1/user/orders/calculate` and `POST /api/v1/vendor/orders/calculate`), when the effective `delivery_fee` is `0` (or `0.00`), the `summary` object includes the boolean flag:

```json
{
  "summary": {
    "subtotal": 100.0,
    "delivery_fee": 0.0,
    "final_amount": 100.0,
    "is_free_delivery": true
  }
}
```

- **When `delivery_fee == 0`**: `"is_free_delivery": true` is present inside `summary`.
- **When `delivery_fee > 0`**: `is_free_delivery` is omitted completely from the JSON response.

---

## 13. `is_free_delivery` Also Added to `GET /user/orders/{orderId}/tracking`

Same field, same rule as §12, now also on the tracking response (top level, alongside `delivery_fee`) — added since it was only on `calculate` before, not on tracking:

- **When `delivery_fee == 0`**: `"is_free_delivery": true` is present at the top level of the tracking response.
- **When `delivery_fee > 0`**: the key is omitted entirely (not `false` — absent).

---

## 14. `payment_breakdown` Showed the Stale Pre-Refund Amount

**Backend-only fix**, found on a real order: originally 60.42 SAR paid in full via wallet, then the vendor rejected 2 services, the order recalculated to 55.86 SAR, and the 4.56 SAR difference was refunded to the wallet. The item-level pricing in the response already reflected the reduction correctly — but `payment_breakdown.payments[].amount` (and `payment_breakdown.total_amount`) still showed **60.42**, the original pre-refund amount.

**Root cause:** a *partial* refund (`OrderPaymentService::markLegPartialRefund()`) never changes the paid leg's `amount` column — it only records the refunded portion in `meta.refunded_amount`, and only flips the leg's `status` to `refunded` once it's refunded **in full**. So a partially-refunded leg stays `status: "paid"` with its original, now-stale `amount`. `buildOrderPaymentBreakdownForApi()` (used by both `Order::paymentBreakdownForApi()` and the tracking endpoint) was summing that raw `amount` column directly, with no awareness of `meta.refunded_amount`.

**Fix:** the breakdown now nets out any partial refund per leg (reusing the same `refundableAmountOnLeg()` helper the refund flow itself already uses internally) before summing, so `payment_breakdown.payments[].amount` and `total_amount` correctly reflect what's actually still paid/owed after a partial refund — 55.86 in this example, not 60.42. A leg refunded to `0` is dropped from `payments` entirely, same as before.

**Not affected:** `final_amount` (the order's own recalculated total) was already correct — only the `payment_breakdown` section was stale. Full refunds (leg status already `refunded`) were also already handled correctly; this only affects *partial* refunds.

---

## Migration Notes

Sections 1–4, 11, 12, and 13 are purely additive — no breaking changes, safe to integrate incrementally:

- No breaking changes — all those fields are new additions to the existing tracking and calculate responses.
- `driver_rejections` will simply be an empty array `[]` for orders with no rejections.
- `handoff` will be `null` and `requires_handoff_confirmation` will be `false` when there's nothing for the client to confirm right now.
- `is_free_delivery` will be present (on `calculate`'s `summary`, and on tracking's top level) only when delivery is free (`delivery_fee == 0`) — otherwise the key is absent.
- `rejected_services` is always included on items as an array `[]`, listing any rejected additional services for that item.

**Section 5 is the one exception** — it changes an actual `status` value for branch-pickup orders. **If any client code currently checks `status === "completed"` to mean "ready for pickup, not yet collected," update it to check `status === "waiting_client_receipt"` (or accept both, for orders already ready before this deploy).**

