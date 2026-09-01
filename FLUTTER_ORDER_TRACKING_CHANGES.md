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

## 15. Stale `payment_breakdown` Amount for COD Orders After Vendor Rejection

**Backend-only fix**, same symptom as §14 but a different root cause — this one specifically for **cash_on_delivery** orders whose price dropped because the vendor rejected/modified items during review. `order.final_amount` recalculated correctly, but `payment_breakdown.total_amount` and `payment_breakdown.payments[0].amount` kept showing the old, pre-rejection total.

**Root cause:** `VendorOrderReviewService::clientApproveModifications()` only adjusted the payment record on a price decrease when `$paidUpfront` was true — and `$paidUpfront` is defined as `$order->isPaid() && ! $order->isCashOnDelivery()`, i.e. it's **always false for COD**, regardless of price change. So the existing (and already correct) mechanism that lowers a payment leg's recorded amount on a decrease (`OrderPaymentService::refundDecrease()` → `reduceCodLegAmount()` for COD specifically — it never issues a cash refund, since nothing was charged, it just lowers the amount still to be collected) was simply never invoked for this path. §14's fix doesn't help here since nothing was refunded — the leg's `amount` column itself was just never updated.

**Fix:** broadened the condition to also run this reconciliation for COD: `($paidUpfront || $order->isCashOnDelivery()) && $delta < -0.005`. `refundDecrease()` already branches correctly per payment method — COD legs get their amount reduced (no cash movement), non-COD paid legs still get a real refund to the original payment method. No new code path, just removed the COD exclusion from an existing, already-tested reduction path (the same one the client's own order-edit flow already uses successfully for COD decreases).

**Found via a live order:** cash_on_delivery order, price dropped from 59.28 → 53.58 after a vendor rejection, with `payment_breakdown.payments[0].amount` stuck at 59.28 — the same class of bug §14 fixed for a wallet-paid order (60.42 → 55.86), confirmed live on the server. This fix hasn't been retested on that order yet post-deploy — do that before considering it closed.

---

## 16. `visit_confirm_label_delivery` / `visit_postpone_label_delivery` Shortened

Not a bug — a wording change on the client's delivery visit-response card (the "driver is on the way" / "driver is approaching" actions card, `GET /user/orders/on-the-way/{id}`).

**Before:** the confirm/postpone buttons carried long labels — "تأكيد الجاهزية لتسليم الطلب" / "تأجيل موعد تسليم السائق" (and in English, "Confirm ready to receive from driver" / "Postpone delivery driver visit").

**Now:** both are short, generic CTAs — "تأكيد" / "تأجيل" ("Confirm" / "Postpone") — matching the target card design. This applies to **every** state of this card, not just the "driver approaching" (≤10 min) one from §7 — that state previously had its own separate short-label override, which is now redundant (removed) since the base labels are short everywhere.

**Where this shows up:** `available_actions.confirm_label` / `available_actions.postpone_label`, and the separate `visit.confirm_label` / `visit.postpone_label` object, both in the `GET /user/orders/on-the-way/{id}` response. The `endpoint`/`confirm_action`/`postpone_action` fields and the actual `POST /user/orders/{id}/visit-response` call are unchanged — only the button text.

**Scoped to the `delivery` visit type only** (this is the only card shown so far) — `visit_confirm_label_pickup`/`visit_postpone_label_pickup` (the home-pickup equivalent) and the branch drop-off/pickup labels are untouched. Ask if those need the same treatment.

---

## 17. `on_way_to_delivery` Card Now Always Uses "Approaching" Wording

Following on from §16: the client asked for the card's **title and message** to match the target design too, for every home-delivery order at status `on_way_to_delivery` — not just once the driver is within 10 minutes.

**Before:** two different texts depending on distance/time:
- Default (driver still far): `title: "التوصيل في الطريق"`, `message: "السائق في الطريق إليك"`
- Approaching (≤10 min, §7 override): `title: "السائق اقترب منك"`, `message: "السائق الآن اقترب من موقعك، جهّز طلبك للاستلام"`

**Now:** the default text itself (`on_the_way_on_way_delivery_title` / `_message` / `_message_distance`) was changed to the approaching wording, so **every** `on_way_to_delivery` response uses it — `title: "السائق اقترب منك!"`, `message: "السائق الآن اقترب من موقعك، جهّز طلبك للاستلام"` (or the `(:distance كم)` variant when distance is known). The §7 ≤10-minute override still exists in the code and still fires, but is now a no-op for this status since it produces the same text — it's only still meaningfully different for `waiting_client_receipt` (unaffected by this change — that status's own text, "السائق سلّم الطلب" / "أكّد أنك استلمت الطلب لإغلاق الطلب", is untouched).

**Where this shows up:** `title` / `message` in the `GET /user/orders/on-the-way/{id}` response (and the mirrored copy inside `vendor_handoff.title`/`vendor_handoff.message`), whenever `status == "on_way_to_delivery"`.

**Not affected:** `on_way_to_pickup` (home pickup, driver en route to collect from the client) keeps its own distinct wording — this change is delivery-side only, matching what was asked.

---

## 18. Delivery "Prepare Order" Wording Corrected

Follow-up to §17. The message ended with "جهّز طلبك للاستلام" (literally "prepare your order for receipt"), which reads oddly — it's the client who receives, not the order. Changed to "جهّز نفسك لاستلام الطلب" ("get yourself ready to receive the order") — but **only** for the messages tied to an actively-tracked driver genuinely en route to the client right now, where "be personally ready" is the correct call to action. The generic fallback keeps the original, more neutral wording since it covers statuses where that presence-specific phrasing wouldn't fit.

| Key | When it's used | Wording |
|---|---|---|
| `on_the_way_on_way_delivery_message` / `_distance` | Driver actively en route, `on_way_to_delivery` | **Changed** → جهّز نفسك لاستلام الطلب / get ready to receive your order |
| `on_the_way_driver_approaching_message` | Driver ≤10 min away | **Changed** → جهّز نفسك لاستلام الطلب / get ready to receive your order |
| `on_the_way_default_message` | Generic fallback for any unmatched status | **Reverted to original** → جهّز طلبك للاستلام / prepare your order for pickup |

---

## 19. `rejected_services` Also Added to `GET /vendor/orders/{id}`

Same fix as §11, applied to the vendor's own order-detail endpoint — it had the identical duplicate-line problem §11 fixed for the client tracking endpoint, just in its own separate item-building code (`Modules/Vendor/.../OrderController.php::show()`).

**The problem:** when the vendor rejects only an additional service on an item they otherwise accepted, `accepted_items` showed the item once (correctly, with only the accepted add-ons) — but a **second, synthetic entry** for the same piece also appeared in `rejected_items`, showing just the rejected add-on, as if it were a separate item.

**The fix:** that synthetic duplicate is gone. Each entry in `accepted_items` now carries a `rejected_services` array (same shape as §11 — `{id, name, price}`), and the corresponding fake `rejected_items` line is no longer created. `rejected_items` now only ever contains pieces/services the vendor rejected **outright** (a whole line rejection). Every entry in both `accepted_items` and `rejected_items` now always includes `rejected_services` — `[]` when nothing was rejected on that item.

**Not touched:** the vendor's `calculate` endpoint (`POST /api/v1/vendor/orders/calculate`, the live-preview-while-reviewing screen) has the same duplicate-line pattern in its own code but was left as-is — showing the just-rejected addition as its own line there may be intentional (the vendor is actively deciding what to reject). Ask if that one needs the same treatment.

---

## Migration Notes

Sections 1–4, 11, 12, and 13 are purely additive — no breaking changes, safe to integrate incrementally:

- No breaking changes — all those fields are new additions to the existing tracking and calculate responses.
- `driver_rejections` will simply be an empty array `[]` for orders with no rejections.
- `handoff` will be `null` and `requires_handoff_confirmation` will be `false` when there's nothing for the client to confirm right now.
- `is_free_delivery` will be present (on `calculate`'s `summary`, and on tracking's top level) only when delivery is free (`delivery_fee == 0`) — otherwise the key is absent.
- `rejected_services` is always included on items as an array `[]`, listing any rejected additional services for that item.

**Section 5 is the one exception** — it changes an actual `status` value for branch-pickup orders. **If any client code currently checks `status === "completed"` to mean "ready for pickup, not yet collected," update it to check `status === "waiting_client_receipt"` (or accept both, for orders already ready before this deploy).**

---

## 20. Critical Pricing Fix: Item's Main Service Price Could Silently Drop to 0

**Real money bug**, found on a live order. Affects `Modules/Order/app/Support/OrderItemGrouper.php::mapGroup()` — the shared function behind **both** `GET /user/orders/{orderId}/tracking` (`items`) and `GET /vendor/orders/{id}` (`accepted_items`/`rejected_items`), so both endpoints were exposed.

**The bug:** for a piece's item row, the main service's price only got added to the line's `unit_price`/`total_price` **if the Eloquent `service` relation resolved** (`if ($item->service) { ... }`). If that relation failed to resolve for any reason (observed on a real order — exact trigger not fully pinned down, but the `service_id` column itself was always intact), the row silently contributed **nothing**: no name, no price, `unit_price` and `total_price` collapsed to just the additional-services total, understating what the client actually owes.

Concretely, on the affected order: a "kids pajama" piece — main service ("غسيل ملابس اطفال") priced 14, one accepted addition (3) — should total 17. Instead it showed `unit_price: 0`, `total_price: 3` (only the accepted addition), with the service's `name` blank. The additional-services calculation (a separate, unconditional code path) was unaffected — only the main service vanished.

**Fix:** the price/id are now taken from the row's own stored `service_id`/`service_price` columns unconditionally — those are always present regardless of whether the `service` relation resolves. The relation is still used, opportunistically, for the display `name`/`icon`; if it doesn't resolve, those come back as an empty string / `null` instead of dropping the whole line's money.

**Residual gap:** if the relation genuinely can't resolve for a given row, the client/vendor will now see the *correct price* but a *blank service name* for that line, instead of losing the money entirely. Worth digging into why the relation failed to resolve for that specific service on that order (deleted/deactivated service record? scoping issue?) as a separate follow-up — this fix guarantees correctness of the amount owed either way, but a blank name is still a rough edge worth chasing down.

---

## 21. Chat Message Push Notifications Were Never Actually Sent

`ChatService` (the module behind `POST /vendor/orders/chat`, the client chat endpoints, and the driver chat endpoints) only synced a new message to Firebase Realtime Database and broadcast it over websockets — both only reach a device that has the chat screen open and connected *right now*. There was no FCM push notification at all, so a message sent while the recipient's app was backgrounded or closed produced nothing on their end.

**The fix:** every message-send path (`sendMessage`, `sendMessageFromVendor`, `sendMessageFromDriver`, `sendMessageFromAdmin`, and the shared `vendorSend`/`driverSend`/`adminSend` flow) now also pushes an FCM notification to the other side(s) of the conversation — sender's name as the title, message text as the body — using `App\Services\FirebaseService::sendChatNotification()`, an already-built method that had no caller anywhere before this. Push failures are swallowed so they never break sending the message itself.

**Not affected by the vendor-notification changes in §28**: this goes through a separate, FCM-only code path (not `UserNotificationService`), so vendor employees always received chat pushes even during the period vendor notifications were blocked elsewhere.

---

## 22. Tax Invoice: Responsive Layout, Self-Hosted QR, Correct Seller Identity

Three separate fixes to the simplified tax invoice page (`GET /api/v1/invoice/{id}` share link, `Modules/Invoice/resources/views/show.blade.php`):

- **Items table wasn't responsive.** Its 7 columns got cut off on narrow phone screens with no way to scroll. Wrapped it in a horizontally-scrollable container and tightened padding/font-size under 600px.
- **ZATCA QR code wasn't rendering reliably.** It was fetched from a third-party image API (`api.qrserver.com`) at page-view time — unreliable depending on the viewer's network. Now generated server-side as inline SVG at invoice-issue time (no runtime network call). Invoices issued before this deploy still use the old external-API image (their cached payload has no SVG), everything issued after gets the inline version.
- **Seller name/VAT number/registration number/address showed the individual vendor's (laundry's) info first**, falling back to the platform's own company settings only if the vendor had none configured. Flipped the priority — the platform's configured identity ("نظيفة" / "Nathefah") is used first now, vendor data is only a fallback. **Only affects invoices issued after this deploy** — an already-issued invoice keeps whatever seller identity was snapshotted onto it at the time.

---

## 23. `rejected_items` Now Also Includes Partial (Addition-Only) Rejections

Follow-up to §11/§19. Those sections correctly stopped `rejected_items` from showing a duplicate line for a piece that stayed accepted but had one of its *additional services* rejected — that info moved to `rejected_services` on the accepted line instead. That was right for the duplication problem, but it also meant a purely-addition-level rejection **stopped showing up in `rejected_items` at all** — and the client/vendor apps render their "Rejected Requests" section from `rejected_items`, not `rejected_services`, so those partial rejections silently disappeared from that section.

**The fix:** `rejected_items` now gets one compact entry for this case too — piece name + just the declined addition(s) + their price (not the item's full price, which correctly stays in the normal/accepted list). The item itself is **not** duplicated or removed from the accepted list; this is purely an additional summary entry so the "Rejected Requests" UI has something to show. Applied to all three places that build this list:

- `GET /user/orders/{orderId}/tracking` — `rejected_items` / `rejected_count`.
- `GET /vendor/orders/{id}` — both the top-level `rejected_items` and the nested `price_breakdown.rejected_items`.
- `GET /user/orders/pending-approval/{orderId}` (and the order-details / on-the-way card responses that share the same builder) — `rejected_items`.

**Shape:** these synthetic entries reuse each endpoint's existing item shape (so no new fields to parse) — `unit_price: 0`, `total_price` = sum of the rejected addition(s), `status: "rejected"`, and `services`/`service_additions` (depending on endpoint) listing just the declined addition(s).

---

## 24. Vendor Order Detail: Additions on a Fully-Rejected Item No Longer Folded Into `services`

When a **whole piece** was rejected (not just one of its additions — see §23 for that case), its additional services used to get merged into the `services` array and `service_additions` cleared to `[]`, so the rejected addition and the rejected main service were indistinguishable in the response.

**The fix:** `service_additions` now stays populated for fully-rejected items too, same as it already was for accepted ones — the app can tell the base service apart from its additions again. Applies to `GET /vendor/orders/{id}` (`rejected_items`) and `POST /vendor/orders/calculate` (`rejected_items`).

---

## 25. Free-Delivery Discount Codes Now Actually Waive Delivery

The discount admin panel has a dedicated "توصيل مجاني" (free delivery) discount type, separate from percentage/fixed — its form has no value field, because a free-delivery discount is 100% off by definition. But the backend was still computing the discount as `delivery_fee × (value / 100)`, and since no value gets submitted for that type, it silently computed to **0** — a "FreeDelievery" coupon discounted nothing.

**The fix:** a discount with `discount_type: "delivery_free"` now waives the delivery fee outright, ignoring `value` entirely. Discounts that apply to delivery via the generic "applies to delivery" flag (a percentage/fixed discount that happens to also cover delivery, as opposed to a dedicated free-delivery code) are unaffected and still use their configured value normally.

**Where this shows up:** `delivery_discount_amount` in the discount-evaluation response (`calculate`, coupon-apply endpoints) for any coupon of this specific type.

---

## 26. `payment_breakdown.payments`: Refunded Legs No Longer Disappear, Plus Refund Detail

Two related fixes to `payment_breakdown` (present on `GET /vendor/orders/{id}`, and anywhere else `Order::paymentBreakdownForApi()` is used):

- **A fully-refunded payment leg used to vanish from `payments` entirely** once the order's overall payment status was no longer simply "paid" (e.g. a split-payment order where one method was refunded in full and another partially kept) — the client only saw the surviving method, with no indication a second method was ever charged or refunded. It's now included, showing the amount that was actually refunded off it.
- **Every entry in `payments` now also carries `refunded_amount`, `refunded_to`, and `refunded_to_label`** — how much came back off that specific method, and where it actually went. Destination isn't always the same as the original method: if a card refund is rejected by the gateway, the system falls back to crediting the client's Nazifah wallet instead (the leg itself stays tagged with its original method, e.g. "visa", even though the money went to the wallet) — these fields reflect the real destination, not just the leg's own `payment_method`.

**Shape (new fields on each `payments[]` entry):**

```json
{
  "payment_method": "visa",
  "payment_method_label": "فيزا",
  "amount": 3.68,
  "status": "refunded",
  "refunded_amount": 3.68,
  "refunded_to": "visa",
  "refunded_to_label": "فيزا"
}
```

`refunded_amount` is `0.0` and `refunded_to`/`refunded_to_label` are `null` when nothing was refunded off that method.

---

## 27. Order Status Notifications: Actor Is Excluded, Vendor Notifications Re-enabled

Previously every order-status change notified client + vendor/admins + drivers uniformly by role, regardless of who actually caused the transition — a client approving their own modifications still got "order confirmed" pushed back at them; a driver marking a pickup complete still got "pickup completed" pushed to themselves. Separately, vendor employees had been blocked from **all** notifications at a single chokepoint as a blanket product decision, unrelated to who acted.

**The fix — one consistent rule:** whoever performed the action that caused a status change does not get notified about it; the other parties still do.

- Vendor and admin are excluded **independently** — a vendor action still reaches admin, and an admin action still reaches the vendor branch.
- For drivers, only the **specific acting driver's own id** is skipped — a pickup driver and delivery driver can be different people, so if one of them causes a transition, the other leg's driver still hears about it normally.
- **Payment-confirmation transitions are treated as receipts, not actions to exclude** — checkout completing (COD selection, wallet payment) and a payment-gateway webhook callback still notify everyone, including the client who just paid, since those are confirmations of a result rather than an echo of something the client already saw happen on their own screen.
- **The vendor notification blackout is lifted.** Vendor employees now receive notifications like every other role (new orders, review updates, driver assignments, etc.), gated by the same actor-exclusion instead of a role-wide block.

**Scope:** this covers everything that goes through the order-status-change event (`OrderStatusChanged` → `SendOrderStatusNotification`) — which is the large majority of order notifications. A few separate notification paths that don't go through that event (e.g. the dedicated driver-assignment ping, the visit-postponed notice) were **not** touched by this change and keep their previous behavior.

**Nothing to change on the client side** — this doesn't add or rename any API field, it only changes who receives which push/in-app notification for a given event.

