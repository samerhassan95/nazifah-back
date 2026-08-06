# Vendor `POST /orders/calculate` — Accepted / Rejected (Display Only)

**Endpoint:** `POST /api/v1/vendor/orders/calculate`  
**Auth:** Vendor employee Bearer token  
**Header:** `Accept-Language: ar` or `en`

> **Important:** `accepted` / `rejected` from this endpoint are **for laundry UI display only**.  
> They do **not** mean the order was actually accepted or rejected in the database.  
> This endpoint does **not** replace `review`. No vendor decision is persisted here.

---

## Important: each physical piece is its own line

Same `piece_id` can appear **multiple times** on an order (e.g. 3 pants).  
Do **not** merge them into one `items[]` row with all services combined.

**Wrong (merges 3 pants into one row):**
```json
{ "piece_id": 43, "service_ids": [66, 67], "additional_service_ids": [78] }
```

**Right (one physical piece only):**
```json
{
  "order_id": 437,
  "items": [
    { "piece_id": 43, "quantity": 1, "service_ids": [66], "additional_service_ids": [78], "note": "" }
  ]
}
```

→ that one pants is accepted; the **other** pants lines on the order go to `rejected_items`.

---

## Contract (what Flutter must implement)

| Request | Meaning | Response |
|---------|---------|----------|
| `{ "order_id": 437, "branch_id": 38 }` only (no `items`) | No action yet | **Everything** in `accepted_items`, `rejected_items` = `[]` |
| `{ "order_id": 437, "items": [ ... ] }` | Body = what laundry keeps | **Sent lines** → `accepted_items` · **Not sent (vs stored order)** → `rejected_items` |

In **both** cases the API always returns:

- `data.accepted_items`
- `data.rejected_items`
- `data.summary` (totals)

Bind the UI to those lists.

---

## Case 1 — No action (open screen / no toggle yet)

```json
{
  "order_id": 437,
  "branch_id": 38
}
```

**Expected:**

- `accepted_items` = all order pieces/services/additions  
- `rejected_items` = `[]`

---

## Case 2 — Laundry toggled accept/reject in UI (preview)

Send **only what should appear as accepted**.  
Anything that exists on the order but is **missing** from `items` is returned in `rejected_items` (display only).

### Reject a whole piece

Do **not** include that piece in `items`.

```json
{
  "order_id": 437,
  "branch_id": 38,
  "items": [
    {
      "piece_id": 43,
      "quantity": 1,
      "service_ids": [66, 67],
      "additional_service_ids": [78],
      "note": ""
    }
  ]
}
```

→ Other stored pieces (not listed) go to `rejected_items`.

### Reject one main service only

Keep the piece, drop the rejected service id from `service_ids`.

```json
{
  "order_id": 437,
  "branch_id": 38,
  "items": [
    {
      "piece_id": 43,
      "quantity": 1,
      "service_ids": [66],
      "additional_service_ids": [78],
      "note": ""
    }
  ]
}
```

If the order had services `[66, 67]`:

- `accepted_items` → piece + service `66` + addition `78`  
- `rejected_items` → same piece + service `67`

### Reject one addition only

Keep services, remove the addition from `additional_service_ids` (or send `[]`).

```json
{
  "order_id": 437,
  "branch_id": 38,
  "items": [
    {
      "piece_id": 43,
      "quantity": 1,
      "service_ids": [66, 67],
      "additional_service_ids": [],
      "note": ""
    }
  ]
}
```

- `accepted_items` → piece + main services  
- `rejected_items` → piece + rejected addition name(s)

---

## Do NOT send this (ambiguous)

Same piece twice (full + reduced):

```json
"items": [
  { "piece_id": 43, "service_ids": [66, 67], "additional_service_ids": [78] },
  { "piece_id": 43, "service_ids": [66], "additional_service_ids": [78] }
]
```

Send **one final accepted version** of each piece only.

---

## Response fields to use in UI

```json
{
  "data": {
    "accepted_items": [ /* status: "accepted" */ ],
    "rejected_items": [ /* status: "rejected" */ ],
    "summary": {
      "subtotal": 11,
      "delivery_fee": 37.12,
      "final_amount": 49.66
    }
  }
}
```

- Accepted section ← `data.accepted_items`  
- Rejected section ← `data.rejected_items`  
- Totals ← `data.summary` (`delivery_fee` = full fee)

For rejected rows, prefer `original_total_price` for the amount shown.

---

## Flutter checklist

1. [ ] Initial load: call calculate with `order_id` (+ optional `branch_id`) only → all accepted.  
2. [ ] On every toggle: rebuild `items` as **current accepted set only**, call calculate again.  
3. [ ] Render `accepted_items` + `rejected_items` every time.  
4. [ ] Never treat calculate accept/reject as a saved business decision.  
5. [ ] Persist real accept/reject later with `POST /vendor/orders/{id}/review` when the vendor confirms (separate step).  
6. [ ] Do not duplicate the same piece with two different `service_ids` sets in one request.

---

## One sentence

> **Whatever you send in `items` is shown as accepted; whatever exists on the order but you omit is shown as rejected — display only, not saved.**  
> **If you send no `items`, everything is shown as accepted.**
