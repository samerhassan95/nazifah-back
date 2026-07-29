# Payment Methods & Payment Statuses

Reference for values returned on order APIs (including `GET /api/v1/driver/orders/{id}`).

## Response Fields

| Field | Description |
|-------|-------------|
| `payment_method` | Raw payment method value |
| `payment_status` | Raw payment status value |
| `payment_status_label` | Localized label for UI display |

Example (order showing Visa + partial refund):

```json
{
  "payment_method": "visa",
  "payment_status": "partially_refunded",
  "payment_status_label": "مسترد جزئياً"
}
```

> Note: Order lifecycle status is separate (`order_status` / `status_label`).  
> Example: `driver_delivery_assigned` → «تم تعيين سائق التوصيل».

---

## Payment Methods (`payment_method`)

Source: `App\Enums\PaymentMethod`

| API value | English | Arabic |
|-----------|---------|--------|
| `cash_on_delivery` | Cash on Delivery | الدفع عند الاستلام |
| `visa` | Visa | فيزا |
| `mastercard` | Mastercard | ماستركارد |
| `mada` | Mada | مدى |
| `nazefah_wallet` | Nazefah Wallet | محفظة نظيفة |
| `stc_pay` | STC Pay | STC Pay |
| `apple_pay` | Apple Pay | Apple Pay |
| `google_pay` | Google Pay | Google Pay |
| `samsung_pay` | Samsung Pay | Samsung Pay |

---

## Payment Statuses (`payment_status`)

Sources:

- `App\Enums\PaymentTransactionStatus`
- `App\Enums\PaymentStatus`
- Labels via `App\Support\PaymentStatusPresenter`

| API value | English (`payment_status_label`) | Arabic (`payment_status_label`) |
|-----------|----------------------------------|---------------------------------|
| `pending` | Pending | قيد الانتظار |
| `authorized` | Authorized | محجوز |
| `completed` | Completed | مكتمل |
| `failed` | Failed | فاشل |
| `cancelled` | Cancelled | ملغي |
| `voided` | Voided | ملغى الحجز |
| `refunded` | Refunded | مسترد بالكامل |
| `partially_refunded` | Partially Refunded | مسترد جزئياً |
| `not_initiated` | Not Initiated | لم يبدأ |

---

## Flutter Notes

- Prefer `payment_status_label` for UI text.
- Prefer translating `payment_method` with local maps / backend labels; do not show raw English keys like `partially_refunded` / `visa` as final UI copy unless intentional.
- `payment_status` is for logic (icons, colors, conditions), not for display.
