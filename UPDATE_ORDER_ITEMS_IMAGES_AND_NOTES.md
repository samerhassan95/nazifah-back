# User Order Update — Item Image & Description Guidelines (Flutter)

**Endpoint:** `PUT /api/v1/orders/{order_id}/update`  
*(If using `multipart/form-data` in Flutter, send a `POST` request with `_method: PUT` in the request body/fields).*

---

## 1. Request Header & Format

- **Authorization:** `Bearer <User Token>`
- **Accept:** `application/json`
- **Content-Type:** 
  - `multipart/form-data` *(Required if uploading images)*
  - `application/json` *(If updating text/notes only)*

---

## 2. Request Body Parameters

When sending `items`, each item inside `items[]` can now accept:

| Field Name | Type | Description |
|------------|------|-------------|
| `items[N][piece_id]` | `int` | **(Required)** Piece ID |
| `items[N][quantity]` | `int` | **(Required)** Quantity |
| `items[N][service_id]` | `int` | **(Required)** Main Service ID |
| `items[N][additional_service_ids][]` | `array<int>` | *(Optional)* Additional Service IDs |
| `items[N][note]` or `items[N][notes]` | `string` | *(Optional)* Client description/note for this specific item (e.g. "بقعة حبر على الياقة") |
| `items[N][image]` | `File` | *(Optional)* Image file upload for this specific item |

---

## 3. Example Multipart Request (Flutter / Postman)

### Fields (Form Data / Multipart):
```text
_method: PUT
items[0][piece_id]: 43
items[0][quantity]: 1
items[0][service_id]: 66
items[0][additional_service_ids][0]: 78
items[0][note]: وصف خاص بالقطعة الأولى
items[0][image]: [Attach File: item1_photo.jpg]

items[1][piece_id]: 43
items[1][quantity]: 1
items[1][service_id]: 67
items[1][note]: وصف خاص بالقطعة الثانية
items[1][image]: [Attach File: item2_photo.jpg]
```

### JSON Request (If no new image is being uploaded):
```json
{
  "items": [
    {
      "piece_id": 43,
      "quantity": 1,
      "service_id": 66,
      "additional_service_ids": [78],
      "note": "تعديل الملاحظة للقطعة الأولى"
    },
    {
      "piece_id": 43,
      "quantity": 1,
      "service_id": 67,
      "note": "تعديل الملاحظة للقطعة الثانية"
    }
  ]
}
```

---

## 4. Key Rules for Flutter Developer

1. **Image Retention:** If `items[N][image]` is omitted during update, the backend retains the existing image for that item (if it already had one).
2. **Per-Item Description:** `note` or `notes` sets the description for that specific item line.
3. **Response Verification:** When calling `GET /api/v1/orders/pending-approval/{order_id}`, `GET /api/v1/orders/{order_id}`, or order tracking endpoints, each item in `accepted_items`, `rejected_items`, and `modified_items` now returns its own distinct `image` (full URL) and `description` / `note`.
