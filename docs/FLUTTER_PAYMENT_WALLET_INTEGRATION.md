# دليل تكامل الدفع وشحن المحفظة — للفلاتر

> **آخر تحديث:** 2026-08-20  
> **Branch:** `main`  
> **Commits ذات الصلة:** `b377bea` (Samsung Pay + card_brand), `fc44feb` (إرجاع wallet deposit لـ invoice URL)

---

## 1. ملخص سريع

| الموضوع | السلوك المطلوب في التطبيق |
|---------|---------------------------|
| **دفع الطلب** | Moyasar SDK (موجود) — **لا** تفتح WebView على `checkout.moyasar.com` |
| **شحن المحفظة** | **نفس** مسار دفع الطلب (Moyasar SDK) — **لا** تفتح `payment_url` في WebView |
| **Samsung Pay / Apple Pay** | بعد الدفع، الـ API يرجع `payment_method = samsung_pay` أو `apple_pay` + `card_brand = visa/mastercard/mada` |
| **عرض UI** | اعرض طريقة المحفظة (سامسونج باي) + شعار/نص الشبكة (فيزا/مدى) من `card_brand` |

---

## 2. مشكلة Samsung Pay في شحن المحفظة (السياق)

### ما كان يحدث
1. شاشة «إيداع في المحفظة» تعرض لوجوهات (مدى، فيزا، STC، **Samsung Pay**) — من `GET /payment-methods`.
2. بعد زر **إيداع**، التطبيق كان يفتح `payment_url` في WebView → صفحة Moyasar الجاهزة (`checkout.moyasar.com`).
3. هذه الصفحة تعرض **STC Pay + البطاقة فقط** — **بدون** زر Samsung Pay.

### السبب
- **دفع الطلب:** التطبيق يستخدم **Moyasar Flutter SDK** → Samsung Pay يظهر.
- **شحن المحفظة:** التطبيق كان يفتح **فاتورة Moyasar** (رابط hosted) → Samsung Pay **لا** يظهر على هذه الصفحة.

### الحل المتفق عليه (Backend + Flutter)
- **Backend:** `POST /wallet/deposit` يرجع `payment_url` (فاتورة Moyasar) للتوافق مع APS/PayFort؛ مع Moyasar يمكن للتطبيق تجاهل `payment_url` واستخدام **`moyasar`** إذا وُجدت لاحقاً، أو — **الأفضل حالياً** — عدم فتح WebView واستخدام **نفس SDK checkout** المستخدم في الطلبات.
- **Flutter:** عند `gateway === 'moyasar'` لشحن المحفظة، **لا** تفتح `payment_url` في WebView. استخدم **نفس شاشة/Widget الدفع** المستخدمة في checkout الطلب (Moyasar SDK).

> **ملاحظة:** تجربة إرجاع `payment_url: null` + `mode: embedded` من الباكند (`530de4f`) أوقفت التطبيق عند رسالة «Payment initialized» بدون شاشة دفع — تم **التراجع** (`fc44feb`). لذلك مسار الإنتاج الحالي: `payment_url` موجود؛ **التطبيق يختار SDK بدلاً من WebView**.

---

## 3. Samsung Pay / Apple Pay + شبكة البطاقة (`card_brand`)

### 3.1 الفكرة
عند الدفع عبر **Samsung Pay** أو **Apple Pay**، Moyasar يرجع في `source`:

```json
{
  "type": "samsungpay",
  "company": "visa"
}
```

| حقل Moyasar | معناه | نخزنه في |
|-------------|--------|----------|
| `source.type` | طريقة المحفظة | `payment_method` → `samsung_pay` / `apple_pay` |
| `source.company` | شبكة البطاقة | `card_brand` → `visa` / `mastercard` / `mada` |

**قبل التعديل:** كان الباكند يحوّل الدفع إلى `visa` فقط ويفقد معلومة Samsung Pay.  
**بعد التعديل:** يُحفظ الاثنان معاً.

### 3.2 Migration (على السيرفر)

```bash
cd /www/wwwroot/back.nathefah.com
git pull origin main
php artisan migrate
php artisan optimize:clear
```

يضيف عمود `card_brand` (nullable) إلى:
- `payment_transactions`
- `orders`
- `wallet_transactions`

### 3.3 متى يُحدَّث `payment_method` و `card_brand`؟
بعد **تأكيد الدفع** من Moyasar (callback / webhook / verify)، عبر `MoyasarPaymentMethodApplier`:

| `source.type` | `source.company` | `payment_method` | `card_brand` |
|---------------|------------------|------------------|--------------|
| `samsungpay` | `visa` | `samsung_pay` | `visa` |
| `samsungpay` | `master` / `mastercard` | `samsung_pay` | `mastercard` |
| `samsungpay` | `mada` | `samsung_pay` | `mada` |
| `applepay` | `visa` | `apple_pay` | `visa` |
| `creditcard` | `visa` | `visa` | `visa` |
| `credit_card` (اختيار عام) + `company: visa` | — | `visa` | `visa` |

---

## 4. شكل الـ API للفلاتر

### 4.1 الطلبات — `paymentFieldsForApi()`

يُستخدم في: تفاصيل الطلب، التتبع، pending approval، إلخ.

```json
{
  "payment_method": "samsung_pay",
  "payment_method_label": "سامسونج باي",
  "payment_methods": ["samsung_pay"],
  "card_brand": "visa",
  "card_brand_label": "فيزا"
}
```

| الحقل | النوع | الوصف |
|-------|------|--------|
| `payment_method` | string | الطريقة الفعلية بعد تأكيد Moyasar |
| `payment_method_label` | string | مترجم حسب `Accept-Language` |
| `payment_methods` | string[] | قائمة طرق الدفع (split / تاريخ) |
| `card_brand` | string \| null | `visa`, `mastercard`, `mada` — فقط عند الدفع عبر wallet أو عند وجود شبكة |
| `card_brand_label` | string | تسمية الشبكة للعرض |

**عرض مقترح في UI:**
```
سامسونج باي · فيزا
```
أو: أيقونة Samsung Pay + أيقونة Visa من `card_brand`.

---

### 4.2 شحن المحفظة — `POST /api/v1/user/wallet/deposit`

**Request (مثال):**
```json
{
  "amount": 50,
  "payment_method": "credit_card"
}
```

**Response (Moyasar — بعد التأكيد يظهر `card_brand` في verify/callback):**

```json
{
  "payment_url": "https://checkout.moyasar.com/invoices/...",
  "transaction_id": "WALLET-123-...",
  "payment_method": "credit_card",
  "payment_method_label": "دفع الكتروني",
  "payment_method_type": "redirect",
  "mode": "invoice",
  "moyasar": {
    "methods": ["creditcard", "stcpay", "applepay", "samsungpay"],
    "supported_networks": ["mada", "visa", "mastercard"]
  },
  "verify_url": "...",
  "grouped_method_values": ["visa", "mastercard", "mada", "stc_pay", "apple_pay", "samsung_pay"]
}
```

**تعليمات Flutter:**

| الحقل | ماذا تفعل |
|-------|-----------|
| `payment_url` | **لا** تفتحه في WebView لـ Moyasar — استخدم SDK |
| `moyasar.methods` | مرجع للطرق المتاحة (اختياري للـ SDK) |
| `verify_url` | بعد نجاح SDK، استدعِ verify أو انتظر callback |

---

### 4.3 معاملات المحفظة — `GET /api/v1/user/wallet`

```json
{
  "txn_id": 150,
  "amount": 10,
  "type": "credit",
  "payment_method": "samsung_pay",
  "payment_method_label": "سامسونج باي",
  "card_brand": "visa",
  "card_brand_label": "فيزا",
  "description": "إيداع في المحفظة",
  "operation_type": "إضافة"
}
```

---

### 4.4 Verify إيداع — `GET /api/v1/user/wallet/deposit/verify/{transactionId}`

بعد دفع ناجح:

```json
{
  "status": "completed",
  "transaction": {
    "payment_method": "samsung_pay",
    "payment_method_label": "سامسونج باي",
    "card_brand": "visa",
    "card_brand_label": "فيزا",
    "amount": 50
  },
  "balance": 60
}
```

---

## 5. قيم `payment_method` و `card_brand`

### payment_method (كاملة)

| value | label (ar) |
|-------|------------|
| `cash_on_delivery` | الدفع عند الاستلام |
| `nazefah_wallet` | محفظة |
| `credit_card` | دفع الكتروني |
| `visa` | فيزا |
| `mastercard` | ماستركارد |
| `mada` | مدى |
| `stc_pay` | STC Pay |
| `apple_pay` | آبل باي |
| `samsung_pay` | سامسونج باي |
| `google_pay` | جوجل باي |

### card_brand (شبكة البطاقة تحت المحفظة)

| value | label (ar) |
|-------|------------|
| `visa` | فيزا |
| `mastercard` | ماستركارد |
| `mada` | مدى |
| `null` | — (لا تعرض شعار شبكة) |

---

## 6. Checklist للفلاتر

### شحن المحفظة
- [ ] عند Moyasar: استخدم **Moyasar SDK** (نفس checkout الطلب).
- [ ] **لا** تفتح `payment_url` في WebView لـ `checkout.moyasar.com`.
- [ ] بعد نجاح الدفع: استدعِ `verify_url` أو اعتمد على callback + refresh المحفظة.

### عرض طريقة الدفع
- [ ] اعرض `payment_method_label` كالطريقة الرئيسية.
- [ ] إذا `card_brand != null`، اعرض `card_brand_label` أو أيقونة الشبكة بجانبها.
- [ ] لا تعتمد على `credit_card` بعد الدفع — انتظر الـ API بعد التأكيد.

### Samsung Pay
- [ ] الزر يظهر من **SDK** على جهاز Samsung (ليس من hosted invoice).
- [ ] Service ID / إعدادات SDK: **نفس** إعدادات دفع الطلب (لا حقل `.env` إضافي في الباكند للفاتورة).

---

## 7. Endpoints مرجعية

| Method | Path | ملاحظة |
|--------|------|--------|
| GET | `/api/v1/user/payment-methods` | طرق الدفع + `grouped_method_values` تحت Moyasar |
| POST | `/api/v1/user/wallet/deposit` | بدء إيداع |
| GET | `/api/v1/user/wallet/deposit/verify/{transactionId}` | تأكيد إيداع |
| GET | `/api/v1/user/wallet` | رصيد + معاملات (فيها `card_brand`) |
| POST | `/api/v1/user/orders` | إنشاء طلب + gateway payment |
| GET | `/api/v1/user/orders/{id}` | تفاصيل + `payment_method` + `card_brand` |

---

## 8. أسئلة شائعة

**س: ليه `payment_url` لسه موجود في deposit؟**  
ج: للتوافق مع PayFort/APS وfallback. مع Moyasar التطبيق **يتجاهله** ويفتح SDK.

**س: `card_brand` بيظهر قبل ولا بعد الدفع؟**  
ج: **بعد** تأكيد Moyasar فقط (callback / verify). قبل الدفع قد يكون `null`.

**س: لو دفع بطاقة عادية (مش Samsung Pay)؟**  
ج: `payment_method = visa|mastercard|mada` و `card_brand` نفس القيمة أو `null` حسب المسار.

**س: Apple Pay؟**  
ج: نفس المنطق: `payment_method = apple_pay` + `card_brand`.

---

## 9. جهة اتصال Backend

- Repo: `samerhassan95/nazifah-back`
- Production: `https://back.nathefah.com`
- بعد أي pull: `php artisan migrate && php artisan optimize:clear`
