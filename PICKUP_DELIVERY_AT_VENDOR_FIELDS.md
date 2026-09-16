<title>Pickup/Delivery At Vendor Fields</title>
# إضافة pickup_at_vendor / delivery_at_vendor لكل الـ endpoints

## المشكلة
الحقلين `pickup_at_vendor` و`delivery_at_vendor` كانوا موجودين في بعض
الـ responses بس مش كلها — أبرز مثال: `GET /vendor/orders`
(قائمة الطلبات عند المغسلة) ما كانتش بترجعهم خالص.

## اللي اتعمل
راجعت كل مكان بيرجع `status_label` في التلات موديولات (user،
vendor، driver) وضفت الحقلين جنبه لو كانوا ناقصين.

### 1) `Modules/Order/app/Http/Resources/VendorOrderResource.php`
ده اللي بيغذي `GET /vendor/orders` (قائمة طلبات المغسلة، زي
الشاشة اللي بعتهالي). كان ناقص الحقلين خالص — اتضافوا.

### 2) `Modules/Driver/app/Http/Controllers/Api/V1/OrderController.php`
أكبر نقص كان هنا — حوالي 13 مكان بيرجعوا `status_label` من غير
الحقلين. معظمهم بيمرّوا على دالة مشتركة واحدة (`orderApiPayload()`)،
فصلحتها فيها مرة واحدة وغطّت كل الأماكن اللي بتستخدمها. الباقي
(زي `mapOrderToDriverListItem()` و`notifyClientOnTheWay()`) اتعدلوا
لوحدهم.

### 3) `Modules/Driver/app/Http/Controllers/Api/V1/HomeController.php`
مكان واحد كان ناقص (قائمة طلبات اليوم للسائق).

### 4) `Modules/Order/app/Http/Controllers/Api/V1/User/OrderController.php`
6 أماكن كانت ناقصة، منها:
- رد الـ Pending Order (قبل ما الأوردر يتعمل فعلياً، وقت الدفع)
- تفاصيل الأوردر (`show`)، تأكيد الاستلام/التسليم، ورد الـ QR scan

### 5) `Modules/Order/app/Http/Controllers/Api/V1/User/OrderTrackingController.php`
مكان واحد كان ناقص (`scanQRCode`).

### اللي كان متغطي بالفعل (متلمسش)
- `Modules/Vendor/app/Http/Controllers/Api/V1/HomeController.php`
- `Modules/Vendor/app/Http/Controllers/Api/V1/OrderController.php`
- باقي أماكن الـ user وdriver اللي كانت أصلاً فيها الحقلين

## الكوميت
- `cb8369a`
