<title>Order Status Labels</title>
# Order Status Labels — user/orders, vendor/orders, driver/orders

`status_label` for every `status` value, as returned by the three order
endpoints. Identical across all three **except `branch_review`**, where
`vendor/orders` reads differently (the vendor already knows it reviewed
the order — see commit `e6e95a7`).

| status | user/orders + driver/orders | vendor/orders |
|---|---|---|
| pending | قيد الانتظار | قيد الانتظار |
| **branch_review** | **تم مراجعة الفرع** | **بانتظار مراجعة العميل** |
| confirmed | مؤكد | مؤكد |
| waiting_payment | في انتظار الدفع | في انتظار الدفع |
| payment_confirmed | تم تأكيد الدفع | تم تأكيد الدفع |
| awaiting_remaining_payment | في انتظار سداد المبلغ المتبقي | في انتظار سداد المبلغ المتبقي |
| driver_pickup_assigned | تم تعيين سائق الاستلام | تم تعيين سائق الاستلام |
| driver_pickup_accepted | قبل سائق الاستلام | قبل سائق الاستلام |
| on_way_to_pickup | في الطريق للاستلام | في الطريق للاستلام |
| picked_up | تم الاستلام | تم الاستلام |
| delivered_to_branch | تم التسليم للفرع | تم التسليم للفرع |
| driver_delivery_assigned | تم تعيين سائق التوصيل | تم تعيين سائق التوصيل |
| driver_delivery_accepted | قبل سائق التوصيل | قبل سائق التوصيل |
| on_way_to_delivery | في الطريق للتوصيل | في الطريق للتوصيل |
| waiting_client_receipt | تم الوصول لموقع التسليم في انتظار استلام العميل | تم الوصول لموقع التسليم في انتظار استلام العميل |
| delivered | تم التوصيل | تم التوصيل |
| client_postponed_pickup | أجل العميل موعد الاستلام | أجل العميل موعد الاستلام |
| client_postponed_delivery | أجل العميل موعد التسليم | أجل العميل موعد التسليم |
| completed | مكتمل | مكتمل |
| cancelled | ملغي | ملغي |

## Context-dependent overrides (not shown in the table above)

A handful of statuses get reworded further based on payment method /
delivery type, regardless of endpoint:

- `payment_confirmed` + cash-on-delivery → "تم تأكيد الطلب"
- `waiting_client_receipt` → wording differs for branch self-pickup vs.
  driver delivery
- `completed` while still awaiting the client's receipt confirmation →
  "جاهز — في انتظار استلامك"

## Where this is implemented

- `app/Enums/OrderStatus.php` — `labelAr()` / `label()` (base table),
  `localizedLabel()` (adds the overrides + the vendor-only branch_review
  override via its `$forVendor` parameter)
- `Modules/Order/app/Models/Order.php` — `status_label` accessor (user +
  driver), `vendor_status_label` accessor (vendor)

## Verified on production

- `GET /user/orders/{id}/tracking` (order 533, `branch_review`) →
  `"status_label":"Branch Review"` (English shown since no
  `Accept-Language: ar` header was sent in the test).
