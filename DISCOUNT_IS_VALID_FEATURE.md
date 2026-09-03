# Discount List — `is_valid` + `invalid_reason`

## Endpoint
`GET /user/orders/get-valid/discounts`

## What changed
The discounts list now tells the client, per discount code, whether it can
actually be applied to the order the user currently has in progress — not
just whether the code exists and is generally active.

### New optional request inputs
| Field | Type | Notes |
|---|---|---|
| `items` | array | Each item: `piece_id` (or `item_type_id`), `service_id`, `quantity`, optional `additional_service_ids` |
| `pickup_address_id` | int | One of the client's own saved addresses |
| `delivery_address_id` | int | One of the client's own saved addresses |

These are all **optional**. If omitted, the endpoint behaves exactly as
before (`is_valid` defaults to `true` for every discount, `invalid_reason`
is `null`).

If `items` is provided, each discount is re-validated for real — using the
exact same validation path (`DiscountService::validateAndCalculateDiscount()`)
that runs when a user actually types the code in at checkout — against:
- the items/prices/quantities passed in,
- the branch (`branch_id`),
- the client's city (resolved from `pickup_address_id`/`delivery_address_id`,
  ownership-checked against the logged-in client).

### New response fields
| Field | Type | Meaning |
|---|---|---|
| `is_valid` | bool | `true` if applying this code right now, with this order, would succeed |
| `invalid_reason` | string\|null | The exact localized reason it failed (e.g. minimum order amount not met, zone not covered, expired, usage limit reached). `null` when `is_valid` is `true`, or when `items` wasn't provided at all. |

`invalid_reason` is the same message the client would see if they typed the
code manually and it got rejected — no separate translation/mapping needed
on the client side.

## Example: live test (branch 38, single item, order_amount = 2.00 SAR)

| Code | min_order_amount | Result | invalid_reason |
|---|---|---|---|
| WEEKEND20 | 50 | `is_valid: false` | الحد الأدنى للطلب 50.00 SAR |
| WEEKEND | 55 | `is_valid: false` | الحد الأدنى للطلب 55.00 SAR |
| WEEKEND30 | 50 | `is_valid: false` | الحد الأدنى للطلب 50.00 SAR |
| WEEKEND22 | 50 | `is_valid: false` | الحد الأدنى للطلب 50.00 SAR |
| Free | 50 | `is_valid: false` | الحد الأدنى للطلب 50.00 SAR |
| sdf34 | 12 | `is_valid: false` | الحد الأدنى للطلب 12.00 SAR |
| test10 | 3 | `is_valid: false` | الحد الأدنى للطلب 3.00 SAR |
| ewr43 | 2 | `is_valid: true` | — (order amount 2.00 meets the 2.00 minimum exactly) |

All eight results here are explained purely by `min_order_amount` vs. the
2.00 SAR test order — no other eligibility rule (zone, vendor, client
restriction, expiry, usage limit) was the blocker in this particular test.

## Example request
```
GET /user/orders/get-valid/discounts?branch_id=38&pickup_address_id=183&delivery_address_id=183&items[0][piece_id]=42&items[0][service_id]=66&items[0][quantity]=1
```
(when testing with `curl`, pass `-g`/`--globoff` — otherwise curl's own
range/glob syntax misinterprets the `[`/`]` in `items[0][...]`.)

## Files changed
- `Modules/Order/app/Http/Controllers/Api/V1/User/OrderController.php`
  — `getDiscounts()`

## Commits
- `daa451c` — add `items`/`pickup_address_id`/`delivery_address_id` inputs
  and the `is_valid` field.
- `cf6024b` — add `invalid_reason` alongside `is_valid`.
