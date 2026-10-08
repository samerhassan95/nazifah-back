# إصلاح دفع Moyasar في Flutter: hosted_local

## ملخص سريع

تم تفعيل `MOYASAR_INTEGRATION_MODE=hosted_local` على الباك إند ونشر التعديل `2b48e2f`. هذا الوضع يعيد رابط صفحة دفع محلية من Laravel، تعرض Moyasar Form للبطاقة وSTC Pay من دون زر Samsung Pay. تغيير `.env` وحده لا يكفي: يجب على Flutter فتح `payment_url` عندما تكون `mode` مساوية لـ `hosted_local`.

## سبب ظهور «لا يوجد معرف دفع حتى الآن»

تدفق المحفظة الحالي يستخدم Moyasar SDK ويتجاهل `payment_url`. في `hosted_local`، الفورم موجود في صفحة Laravel وليس داخل SDK الأصلي. إذا استمر التطبيق بتشغيل SDK بدل فتح الرابط، فلن يبدأ فورم الدفع ولن يصل `payment_id`، فتظل معاملة المحفظة `pending`.

## تدفق البطاقة وSTC Pay المطلوب

1. استدعِ `POST /api/v1/user/wallet/deposit` كالمعتاد لطريقة البطاقة أو STC Pay.
2. افحص استجابة API، ولا تعتمد على `gateway` وحده لتحديد شاشة الدفع.
3. إذا كانت `gateway == "moyasar"` و`mode == "hosted_local"`، افتح قيمة `payment_url` كما هي في WebView. لا تستدعِ Moyasar SDK لهذا المسار.
4. اسمح بالتنقلات المطلوبة لـ 3-D Secure. إذا فتح STC Pay تطبيقًا خارجيًا، استخدم معالج الروابط الحالي في التطبيق للعودة والتحقق.
5. عند وصول WebView إلى `callback_url`، استخرج `id` أو `payment_id` من الرابط، ثم أكّد العملية من الخادم باستخدام `verify_url` مع `id` كـ query parameter. لا تعتبر إغلاق WebView أو نجاح JavaScript إثباتًا للدفع.
6. إذا أعاد التأكيد `202` وحالة `pending`، أعد المحاولة وفق آلية polling الحالية. عند `200` و`completed` أغلق شاشة الدفع وحدّث رصيد المحفظة. اعرض الفشل عند استجابة نهائية غير ناجحة.

صفحة Laravel المحلية تستخدم Moyasar Form، وتحذف `samsungpay` من قائمة الطرق. على Android ستبقى البطاقة وSTC Pay؛ Apple Pay يظهر فقط على الأجهزة/المنصات المدعومة.

## تدفق Samsung Pay الأصلي

Samsung Pay يظل في مساره الأصلي داخل التطبيق، ولا يفتح صفحة `hosted_local`:

1. نفّذ الدفع باستخدام Moyasar SDK الأصلي كما هو مطبق حاليًا.
2. عند نجاح SDK، أرسل `payment_id` الذي أعاده Moyasar إلى:

```http
POST /api/v1/user/wallet/deposit/moyasar/confirm
Content-Type: application/json
Authorization: Bearer <access-token>
```

```json
{
  "payment_id": "<id returned by Moyasar SDK>"
}
```

3. انتظر استجابة الخادم وحدّث الرصيد فقط بعد أن يتحقق الخادم من الدفع ويعيد `status: completed`.

لا ترسل مبلغًا من التطبيق باعتباره إثباتًا للدفع؛ الخادم يجلب المبلغ والحالة من Moyasar. كذلك لا تمرر اختيار Samsung Pay إلى تدفق `hosted_local`: واجهة إيداع المحفظة تطبّع طرق البطاقات والمحافظ إلى `credit_card` لهذا الفورم.

## شكل استجابة متوقع

قد تختلف أغلفة JSON، لكن الحقول المهمة في استجابة بدء الإيداع هي:

```json
{
  "gateway": "moyasar",
  "mode": "hosted_local",
  "payment_method_type": "redirect",
  "payment_url": "https://back.nathefah.com/api/v1/payments/moyasar/checkout/<transaction-id>",
  "transaction_id": "<transaction-id>",
  "verify_url": "https://back.nathefah.com/api/v1/user/wallet/deposit/verify/<transaction-id>",
  "moyasar": {
    "methods": ["creditcard", "stcpay", "applepay"]
  }
}
```

لا تتوقع ظهور `samsungpay` ضمن طرق الفورم المحلية.

## التوافق والاختبار

- إذا كانت `mode == "invoice"`، أبقِ سلوك Moyasar الحالي كما هو.
- لا تغيّر تدفقات بوابات الدفع الأخرى.
- على بيئة الاختبار الحالية، `MOYASAR_TEST_MODE=true`؛ استخدم مفاتيح الاختبار فقط. لا تنفّذ اختبارًا حيًا دون اعتماد بيانات الإنتاج.
- اختبر على Android: ظهور البطاقة وSTC Pay، وعدم ظهور Samsung Pay داخل WebView، نجاح 3-D Secure، عودة التطبيق، ثم تأكيد الإيداع وتحديث الرصيد مرة واحدة فقط.
- اختبر Samsung Pay منفصلًا عبر SDK الأصلي وendpoint التأكيد المذكور أعلاه.
- عند التشخيص، شارك قيم `mode` و`payment_method_type` ووجود `payment_url` فقط. لا تشارك ملف `.env` أو مفاتيح API أو tokens.
