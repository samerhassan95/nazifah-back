# Payment & Wallet Top-up Integration Guide — Flutter

> **Last updated:** 2026-08-20  
> **Branch:** `main`  
> **Related commits:** `b377bea` (Samsung Pay + card_brand), `fc44feb` (revert wallet deposit SDK-only response), `64ff76d` (this doc)

---

## 1. Quick summary

| Topic | Required app behavior |
|-------|----------------------|
| **Order checkout** | Moyasar SDK (already in place) — **do not** open a WebView to `checkout.moyasar.com` |
| **Wallet top-up** | **Same** flow as order checkout (Moyasar SDK) — **do not** open `payment_url` in a WebView |
| **Samsung Pay / Apple Pay** | After payment, API returns `payment_method = samsung_pay` or `apple_pay` plus `card_brand = visa/mastercard/mada` |
| **UI display** | Show the wallet method (Samsung Pay) + network logo/label (Visa/Mada) from `card_brand` |

---

## 2. Samsung Pay on wallet top-up (context)

### What was happening
1. The “Deposit to wallet” screen shows logos (Mada, Visa, STC, **Samsung Pay**) from `GET /payment-methods`.
2. After tapping **Deposit**, the app opened `payment_url` in a WebView → Moyasar hosted page (`checkout.moyasar.com`).
3. That page shows **STC Pay + card entry only** — **no** Samsung Pay button.

### Why
- **Order checkout:** app uses **Moyasar Flutter SDK** → Samsung Pay appears.
- **Wallet top-up:** app opened **Moyasar hosted invoice** URL → Samsung Pay **does not** appear on that page.

### Agreed fix (Backend + Flutter)
- **Backend:** `POST /wallet/deposit` still returns `payment_url` (Moyasar invoice) for APS/PayFort compatibility. For Moyasar, the app should **ignore** `payment_url` and use the **same SDK checkout** as orders.
- **Flutter:** when `gateway === 'moyasar'` for wallet top-up, **do not** open `payment_url` in a WebView. Use the **same payment screen/widget** as order checkout (Moyasar SDK).

> **Note:** Returning `payment_url: null` + `mode: embedded` from the backend (`530de4f`) left the app stuck on “Payment initialized” with no payment UI — **reverted** (`fc44feb`). Current production: `payment_url` is present; **the app must choose SDK over WebView**.

---

## 3. Samsung Pay / Apple Pay + card network (`card_brand`)

### 3.1 Concept
When paying via **Samsung Pay** or **Apple Pay**, Moyasar returns in `source`:

```json
{
  "type": "samsungpay",
  "company": "visa"
}
```

| Moyasar field | Meaning | Stored as |
|---------------|---------|-----------|
| `source.type` | Wallet method | `payment_method` → `samsung_pay` / `apple_pay` |
| `source.company` | Card network | `card_brand` → `visa` / `mastercard` / `mada` |

**Before:** backend overwrote payment as `visa` only and lost Samsung Pay info.  
**After:** both fields are stored.

### 3.2 Migration (server)

```bash
cd /www/wwwroot/back.nathefah.com
git pull origin main
php artisan migrate
php artisan optimize:clear
```

Adds nullable `card_brand` to:
- `payment_transactions`
- `orders`
- `wallet_transactions`

### 3.3 When are `payment_method` and `card_brand` updated?
After **payment confirmation** from Moyasar (callback / webhook / verify), via `MoyasarPaymentMethodApplier`:

| `source.type` | `source.company` | `payment_method` | `card_brand` |
|---------------|------------------|------------------|--------------|
| `samsungpay` | `visa` | `samsung_pay` | `visa` |
| `samsungpay` | `master` / `mastercard` | `samsung_pay` | `mastercard` |
| `samsungpay` | `mada` | `samsung_pay` | `mada` |
| `applepay` | `visa` | `apple_pay` | `visa` |
| `creditcard` | `visa` | `visa` | `visa` |
| generic `credit_card` + `company: visa` | — | `visa` | `visa` |

---

## 4. API shapes for Flutter

### 4.1 Orders — `paymentFieldsForApi()`

Used in: order details, tracking, pending approval, etc.

```json
{
  "payment_method": "samsung_pay",
  "payment_method_label": "Samsung Pay",
  "payment_methods": ["samsung_pay"],
  "card_brand": "visa",
  "card_brand_label": "Visa"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `payment_method` | string | Actual method after Moyasar confirmation |
| `payment_method_label` | string | Localized via `Accept-Language` |
| `payment_methods` | string[] | Payment method list (split / history) |
| `card_brand` | string \| null | `visa`, `mastercard`, `mada` — when paying via wallet or when network is known |
| `card_brand_label` | string | Network label for display |

**Suggested UI:**
```
Samsung Pay · Visa
```
Or: Samsung Pay icon + Visa icon from `card_brand`.

---

### 4.2 Wallet top-up — `POST /api/v1/user/wallet/deposit`

**Request (example):**
```json
{
  "amount": 50,
  "payment_method": "credit_card"
}
```

**Response (Moyasar — `card_brand` appears after verify/callback):**

```json
{
  "payment_url": "https://checkout.moyasar.com/invoices/...",
  "transaction_id": "WALLET-123-...",
  "payment_method": "credit_card",
  "payment_method_label": "Digital Payment",
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

**Flutter instructions:**

| Field | Action |
|-------|--------|
| `payment_url` | **Do not** open in WebView for Moyasar — use SDK |
| `moyasar.methods` | Reference for available methods (optional for SDK) |
| `verify_url` | After SDK success, call verify or rely on callback |

---

### 4.3 Wallet transactions — `GET /api/v1/user/wallet`

```json
{
  "txn_id": 150,
  "amount": 10,
  "type": "credit",
  "payment_method": "samsung_pay",
  "payment_method_label": "Samsung Pay",
  "card_brand": "visa",
  "card_brand_label": "Visa",
  "description": "Wallet deposit",
  "operation_type": "Addition"
}
```

---

### 4.4 Deposit verify — `GET /api/v1/user/wallet/deposit/verify/{transactionId}`

After successful payment:

```json
{
  "status": "completed",
  "transaction": {
    "payment_method": "samsung_pay",
    "payment_method_label": "Samsung Pay",
    "card_brand": "visa",
    "card_brand_label": "Visa",
    "amount": 50
  },
  "balance": 60
}
```

---

## 5. `payment_method` and `card_brand` values

### payment_method (full list)

| value | label (en) | label (ar) |
|-------|------------|------------|
| `cash_on_delivery` | Cash on Delivery | الدفع عند الاستلام |
| `nazefah_wallet` | Wallet | محفظة |
| `credit_card` | Digital Payment | دفع الكتروني |
| `visa` | Visa | فيزا |
| `mastercard` | MasterCard | ماستركارد |
| `mada` | MADA | مدى |
| `stc_pay` | STC Pay | STC Pay |
| `apple_pay` | Apple Pay | آبل باي |
| `samsung_pay` | Samsung Pay | سامسونج باي |
| `google_pay` | Google Pay | جوجل باي |

### card_brand (network under wallet payment)

| value | label (en) | label (ar) |
|-------|------------|------------|
| `visa` | Visa | فيزا |
| `mastercard` | MasterCard | ماستركارد |
| `mada` | MADA | مدى |
| `null` | — (hide network badge) | — |

Labels come from the API (`*_label` fields) based on `Accept-Language`.

---

## 6. Flutter checklist

### Wallet top-up
- [ ] For Moyasar: use **Moyasar SDK** (same as order checkout).
- [ ] **Do not** open `payment_url` in WebView for `checkout.moyasar.com`.
- [ ] After successful payment: call `verify_url` or rely on callback + refresh wallet.

### Payment method display
- [ ] Show `payment_method_label` as the primary method.
- [ ] If `card_brand != null`, show `card_brand_label` or network icon next to it.
- [ ] Do not rely on `credit_card` after payment — wait for post-confirmation API.

### Samsung Pay
- [ ] Button comes from **SDK** on Samsung devices (not hosted invoice).
- [ ] Service ID / SDK config: **same** as order checkout (no extra backend `.env` for hosted invoice).

---

## 7. Reference endpoints

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/user/payment-methods` | Payment methods + `grouped_method_values` under Moyasar |
| POST | `/api/v1/user/wallet/deposit` | Start deposit |
| GET | `/api/v1/user/wallet/deposit/verify/{transactionId}` | Confirm deposit |
| GET | `/api/v1/user/wallet` | Balance + transactions (includes `card_brand`) |
| POST | `/api/v1/user/orders` | Create order + gateway payment |
| GET | `/api/v1/user/orders/{id}` | Details + `payment_method` + `card_brand` |

Base URL (production): `https://back.nathefah.com/api/v1`

---

## 8. FAQ

**Q: Why is `payment_url` still returned on deposit?**  
A: Compatibility with PayFort/APS and fallback. With Moyasar, the app **ignores** it and uses SDK.

**Q: When does `card_brand` appear — before or after payment?**  
A: **After** Moyasar confirmation only (callback / verify). Before payment it may be `null`.

**Q: Regular card payment (not Samsung Pay)?**  
A: `payment_method = visa|mastercard|mada`; `card_brand` may match or be `null` depending on path.

**Q: Apple Pay?**  
A: Same logic: `payment_method = apple_pay` + `card_brand`.

**Q: Split payments?**  
A: Use `payment_methods[]` array; each leg may have its own method. Order-level `card_brand` reflects the primary gateway leg after resolution.

---

## 9. Backend contact

- Repo: `samerhassan95/nazifah-back`
- Production: `https://back.nathefah.com`
- After any pull: `php artisan migrate && php artisan optimize:clear`
