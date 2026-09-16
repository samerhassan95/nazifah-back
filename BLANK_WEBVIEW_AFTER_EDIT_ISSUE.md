<title>Blank WebView After Order Edit</title>
# Blank WebView After Order Edit — Not a Backend Bug

## The symptom
After a client edits an order (adds/removes items) and taps "تنفيذ
التعديلات" (apply changes), a payment WebView sometimes opens showing
a **blank white page** — even though, per the backend's own numbers,
the customer doesn't actually owe anything for that specific edit.

## Root cause: the app opens a payment WebView unconditionally
`PUT /user/orders/{id}/update` **always** applies the edit in the same
request. Whether a *separate* payment step is also needed depends
entirely on whether the price actually went up and, if so, by how the
client chose to settle it. The response's `data.payment` field is the
single source of truth for this:

- **`payment: null`** → nothing left to do. The edit already fully
  applied. There is no payment URL, because there is nothing to pay.
- **`payment: {...}`** → a real payment step is needed; `payment.gateway_payments[0].payment_url`
  (or `.payment_params`) is what should be opened in the WebView.

The blank white page happens when the app opens the WebView **without
checking whether `payment` is null first** — it has no URL to load
(because none was ever returned), so the WebView just renders empty.

## Concrete example — the exact scenario that triggered this
Order `ORD-20260907-38507` (id 524):

1. Created: 1 dress with "كي الملابس بالبخار" (2 SAR) + "تعطير الملابس"
   addition (1 SAR) = 3.00 SAR, tax 0.45 → **paid 3.45 SAR**.
2. Vendor review rejected the "تعطير الملابس" addition.
3. Client edited the order — in the **same edit** they removed that
   addition and added a **different** one, "تعليق الملابس", at the
   **same price** (1 SAR).

Net effect of step 3: the rejected 1 SAR addition and the newly added
1 SAR addition cancel out exactly. The order's total before and after
this specific edit is identical (3.45 SAR both times). There is
nothing to refund and nothing to charge for this edit.

### What the backend actually returned for that edit
```json
{
  "status": true,
  "code": 200,
  "message": "تم تحديث الطلب بنجاح",
  "data": {
    "order": {
      "order_id": 524,
      "order_number": "ORD-20260907-38507",
      "status": "pending",
      "total_amount": 3.00,
      "final_amount": 3.45
    },
    "payment": null
  }
}
```

`payment` is `null` — correctly signaling "the edit is done, nothing
else needed." If the app opened a WebView after this response anyway,
that's where the blank page came from.

## The rule the app needs to follow
```
if (response.data.payment == null) {
  // Edit is fully applied. Show success. Do NOT open any WebView.
} else {
  // A real payment is needed to complete the edit.
  final url = response.data.payment.gateway_payments[0]?.payment_url
      ?? response.data.payment.payment_params;
  openWebView(url);
}
```

There is no case where the backend returns a truthy `payment` object
with a missing/empty `payment_url` — if `payment` is present, a URL is
present. The only way to get an empty/blank WebView is to open one
when `payment` was `null` to begin with.

## Why this is a Flutter-side fix, not a backend one
The backend's contract here is unconditional and already correct: it
computes the real delta (backed by the same fixes documented in
`SESSION_CHANGES_SUMMARY_2026-09-06_07.md`, items #7 and #10) and
returns `payment: null` whenever nothing is actually owed, `payment: {...}`
with a real `payment_url` whenever something is. The app is expected
to branch on that field before deciding to open a WebView at all — it
currently doesn't, and that's the entire bug.

## What to check in the Flutter code
Find where `PUT /orders/{id}/update`'s response is handled after
tapping "تنفيذ التعديلات", and confirm there's a null-check on
`payment` (or `payment.gateway_payments`) *before* any WebView/browser
launch call — not after, and not unconditionally.
