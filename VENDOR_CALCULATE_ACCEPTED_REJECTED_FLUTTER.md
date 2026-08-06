# Vendor `POST /orders/calculate` — Accepted / Rejected (Display Only)

**Endpoint:** `POST /api/v1/vendor/orders/calculate`  
**Auth:** Vendor employee Bearer token  
**Header:** `Accept-Language: ar` or `en`

> **Important**  
> - `accepted_items` / `rejected_items` here are **for laundry UI display only**.  
> - They are **not** saved to the database.  
> - This does **not** replace `POST /vendor/orders/{id}/review` (real accept/reject happens there later).

---

## Core contract

| Request | Meaning | Response |
|---------|---------|----------|
| `{ "order_id", "branch_id" }` only — **no `items`** | No action yet | All order lines → `accepted_items` · `rejected_items` = `[]` |
| `{ "order_id", "items": [ ... ] }` | Body = what laundry **keeps** | Each sent line → `accepted_items` · each stored line **not matched** → `rejected_items` |

Always read:

- `data.accepted_items`
- `data.rejected_items`
- `data.summary` (use `summary.delivery_fee` for full delivery fee)

---

## Critical rule: one physical piece = one `items[]` row

On the server, **each `order_item` row is one physical piece**, even if `piece_id` is the same.

Example order **437** in DB:

| # | piece_id | service_ids | additional_service_ids |
|---|----------|-------------|------------------------|
| 1 | 43 (pants) | `[66]` | `[78]` |
| 2 | 43 (pants) | `[67]` | `[]` |
| 3 | 43 (pants) | `[66]` | `[78]` |

→ **3 pants**, not 1 pants with 3 services.

### Wrong (merges pants into one row)

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

This does **not** match any single stored row (no row has exactly `[66, 67]`).  
Result: the body line may show as accepted, and **all 3 real pants** go to `rejected_items`.

### Right (one pants only)

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

**Expected:**

- `accepted=1` → pants + service `66` + addition `78`
- `rejected=2` → the other pants (`67`) + the other pants (`66`+`78`)

Matching is **one-to-one**: identical services on two pants are still two lines. Sending one accepted row matches **one** stored row; the twin stays rejected.

---

## How matching works

For each `items[]` entry, the server finds **at most one** unused stored row where:

1. Same `piece_id`
2. Same `service_ids` set (exact)
3. Prefer exact `additional_service_ids`; allow request additions to be a **subset** (dropped adds = rejected additions)

Then that stored row is **consumed** (cannot match again).

Anything left unmatched in DB → `rejected_items`.

---

## All send cases

### Case A — No action (open screen)

```json
{
  "order_id": 437,
  "branch_id": 38
}
```

| Field | Result |
|-------|--------|
| `accepted_items` | All order lines |
| `rejected_items` | `[]` |

---

### Case B — Accept one piece, reject the other pieces

Send **only** the kept piece line(s). Omit the rest.

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

| Field | Result |
|-------|--------|
| `accepted_items` | That one pants |
| `rejected_items` | Every other stored pants/piece |

---

### Case C — Accept two identical pants, reject the third

Send the accepted line **twice** (same `piece_id` / services / adds):

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
    },
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

| Field | Result |
|-------|--------|
| `accepted_items` | 2 |
| `rejected_items` | 1 (the remaining pants, e.g. service `67`) |

---

### Case D — Reject one addition on a kept piece

Keep the piece + services; remove the addition from `additional_service_ids` (or send `[]`).

Stored row: `service_ids: [66]`, `additional_service_ids: [78]`

```json
{
  "order_id": 437,
  "branch_id": 38,
  "items": [
    {
      "piece_id": 43,
      "quantity": 1,
      "service_ids": [66],
      "additional_service_ids": [],
      "note": ""
    }
  ]
}
```

| Field | Result |
|-------|--------|
| `accepted_items` | Pants + service `66` (no addition) |
| `rejected_items` | Pants + rejected addition name(s), **and** any other unmatched pants |

---

### Case E — Reject one main service on a multi-service **single** stored row

Only if **one** DB row actually has multiple services together (rare with current mapping — usually one service per row).

If a stored line is `service_ids: [66, 67]`:

```json
{
  "piece_id": 43,
  "quantity": 1,
  "service_ids": [66],
  "additional_service_ids": [78],
  "note": ""
}
```

| Field | Result |
|-------|--------|
| `accepted_items` | Piece + service `66` (+ kept adds) |
| `rejected_items` | Same piece instance + omitted service `67` |

> On order 437, services `66` and `67` are **different pants rows**. Do not use Case E by merging `[66, 67]` in one request row — use Case B/C instead.

---

### Case F — Reject everything

You cannot send “empty keep list” as `items: []` — empty `items` is treated like **no items** (Case A = all accepted).

To reject all pieces for display, you would need a different product rule; today: either keep at least one accepted line, or handle “reject all” in the UI without relying on empty `items`.

---

## Optional: `item_id` + `status` overlay

Also supported (same display-only behavior):

```json
{
  "order_id": 437,
  "branch_id": 38,
  "items": [
    { "item_id": 1520, "status": "accepted" },
    { "item_id": 1521, "status": "rejected" }
  ]
}
```

Prefer this if the app already has order `item_id`s.  
Catalog-shaped omit flow (Cases B–D) does **not** require `status`.

---

## Response shape (UI binding)

```json
{
  "data": {
    "accepted_items": [
      {
        "piece_id": 43,
        "item_name": "بنطال",
        "status": "accepted",
        "services": [{ "id": 66, "name": "...", "price": 8 }],
        "service_additions": [{ "id": 78, "name": "...", "price": 3 }],
        "total_price": 11
      }
    ],
    "rejected_items": [
      {
        "piece_id": 43,
        "item_name": "بنطال",
        "status": "rejected",
        "services": [{ "id": 67, "name": "..." }],
        "original_total_price": 5
      }
    ],
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
- For rejected amounts prefer `original_total_price`

---

## Do NOT send

1. **Merged pants** — one row with `service_ids: [66, 67]` when DB has separate rows.  
2. **Full + reduced duplicate** of the same piece in one request:
   ```json
   "items": [
     { "piece_id": 43, "service_ids": [66, 67], "additional_service_ids": [78] },
     { "piece_id": 43, "service_ids": [66], "additional_service_ids": [78] }
   ]
   ```
3. **`order_id` only** and expect rejects — with no `items`, everything is accepted.  
4. Treating calculate accept/reject as a saved business decision — use `review` to persist later.

---

## Flutter checklist

1. [ ] Initial open: `calculate` with `order_id` (+ `branch_id`) only → all accepted.  
2. [ ] Build `items` as **one entry per accepted physical piece** (one DB row’s services/adds).  
3. [ ] On every toggle: rebuild `items`, call `calculate` again.  
4. [ ] Bind UI to `accepted_items` + `rejected_items`.  
5. [ ] Identical services on two pieces → send two identical rows to accept both.  
6. [ ] Never merge different pants into one `service_ids` array.  
7. [ ] Persist real decisions later with `POST /vendor/orders/{id}/review`.

---

## One sentence

> **No `items` → everything accepted for display.  
> With `items` → each sent row is one accepted physical piece; every unmatched stored row is rejected for display.  
> Same `piece_id` can mean many pieces — never merge them.**
