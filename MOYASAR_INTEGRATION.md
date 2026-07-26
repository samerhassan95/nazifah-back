# Moyasar Integration — Activation Checklist

This is the practical, do-this-list to turn on **Moyasar** as the active payment gateway,
in both **test** and **live** modes. Moyasar was added as a second gateway with full parity
to the existing Amazon Payment Services (APS / PayFort) integration, and the platform can switch
between the two from the admin panel **without a code deploy**.

> Both gateways stay wired and ready at all times. Exactly **one** is active for real
> transactions. Switching the active gateway does **not** affect in-flight transactions —
> each one is captured/voided/refunded by the gateway recorded on its own transaction row.

---

## 0. TL;DR — the minimum to go live

1. Get your Moyasar **secret key** and **publishable key** (live: `sk_live_…` / `pk_live_…`).
2. Put them in `.env` (see [§1](#1-api-keys--secrets)). Run `php artisan config:clear` (or `config:cache`).
3. Register the **webhook URL** in the Moyasar dashboard (see [§3](#3-webhook-url-register-in-moyasar-dashboard)) and copy the **webhook secret** into `.env`.
4. Confirm the open API details in [§9](#9-must-confirm-before-go-live-do-not-skip) against Moyasar's docs.
5. Test in sandbox first (`MOYASAR_TEST_MODE=true` + test keys), run a full pay → confirm → refund cycle.
6. Switch the active gateway to Moyasar via the admin API (see [§6](#6-switch-the-active-gateway-admin)).

---

## 1. API keys & secrets

Moyasar authenticates with a **secret API key** (HTTP Basic auth, key as username, empty password).
There is **no separate merchant ID** like APS — the key *is* the account/merchant identity, and its
prefix selects the environment (`sk_test_…` vs `sk_live_…`). The publishable key (`pk_…`) is only needed
if you later add a client-side card form; it is stored for completeness but the current server-side
invoice flow doesn't require it.

Get them from: **Moyasar Dashboard → Developers / API Keys**.

Put them in `.env` (preferred). Wrap in single quotes:

```dotenv
# Sandbox toggle: true = test keys, false = live keys. Default false (fail-to-live, like APS).
MOYASAR_TEST_MODE=false

# Live keys (used when MOYASAR_TEST_MODE=false)
MOYASAR_SECRET_KEY='sk_live_xxxxxxxxxxxxxxxxxxxx'
MOYASAR_PUBLISHABLE_KEY='pk_live_xxxxxxxxxxxxxxxxxxxx'
MOYASAR_WEBHOOK_SECRET='your-webhook-shared-secret'

# Test keys (used when MOYASAR_TEST_MODE=true)
MOYASAR_TEST_SECRET_KEY='sk_test_xxxxxxxxxxxxxxxxxxxx'
MOYASAR_TEST_PUBLISHABLE_KEY='pk_test_xxxxxxxxxxxxxxxxxxxx'
MOYASAR_TEST_WEBHOOK_SECRET='your-test-webhook-shared-secret'

# Keep the gateway registered & ready to activate (does NOT make it active)
MOYASAR_ENABLED=true
```

> **Config cache gotcha (same as APS):** on a server that runs `php artisan config:cache`, editing
> `.env` has **no effect** until you run `php artisan config:clear` (reads `.env` live) or
> `php artisan config:cache` (rebuilds). Verify the resolved value, not the `.env` text:
> ```bash
> php artisan tinker --execute="echo config('payment.gateways.moyasar.test_mode') ? 'TEST' : 'LIVE';"
> php artisan tinker --execute="echo config('payment.gateways.moyasar.secret_key') ? 'secret set' : 'MISSING';"
> ```

**Fallback file (optional):** if your deploy can't set env vars at config-cache time, you can instead
fill `config/payment_credentials.php` → `moyasar.test` / `moyasar.live`. Keep that file **gitignored**;
never commit real keys.

---

## 2. Callback / redirect URL (customer's browser)

After paying on Moyasar's hosted page, the customer's browser is redirected back to our callback. This
is wired automatically by the code — you do **not** register it in the dashboard, but make sure your
`APP_URL` is correct and publicly reachable over HTTPS, because the gateway builds the redirect from it.

The app uses the **existing** callback routes (shared with APS) depending on the flow:

| Flow | Callback URL the app sends to Moyasar |
|---|---|
| Order checkout (existing order) | `https://<APP_URL>/api/v1/checkout/callback?order_id=…&merchant_reference=…` |
| Wallet deposit / pending-order / direct payment | `https://<APP_URL>/api/v1/payments/callback?…&merchant_reference=…` |

No action needed beyond a correct, HTTPS, public `APP_URL`.

---

## 3. Webhook URL (register in Moyasar dashboard)

The webhook is the **reliable** settlement path (the browser redirect can be lost if the customer closes
the tab). **Register this URL** in **Moyasar Dashboard → Developers → Webhooks**:

```
https://<APP_URL>/api/v1/payments/moyasar/webhook
```

- Method: **POST** (public route, no auth — it is verified by the shared secret, see below).
- Subscribe to the payment events you care about — at minimum **payment_paid** and **payment_failed**
  (and **payment_refunded** if you want refund confirmations). _CONFIRM the exact event names in the dashboard._
- When you create the webhook, Moyasar lets you set a **shared secret / signing secret** — copy it into
  `MOYASAR_WEBHOOK_SECRET` (or `MOYASAR_TEST_WEBHOOK_SECRET`).

> The webhook handler **rejects** unverified requests. If `MOYASAR_WEBHOOK_SECRET` is empty it returns 401.
> You can temporarily disable verification with `PAYMENT_WEBHOOKS_VERIFY_SIGNATURE=false` (NOT recommended in prod).

---

## 4. Currency & locale

```dotenv
MOYASAR_CURRENCY=SAR        # matches APS; amounts are sent to Moyasar in halalas (×100) automatically
MOYASAR_LANGUAGE=ar
```

Amounts are converted to the smallest unit (halalas) by the gateway — you configure prices in SAR as today.

---

## 5. Capture model (test/sandbox vs live behaviour)

```dotenv
# PURCHASE      = charge immediately (DEFAULT — universally supported, recommended)
# AUTHORIZATION = place a hold, capture/void later (mirrors APS auth→capture)
MOYASAR_COMMAND=PURCHASE
```

- **PURCHASE (default):** payment is captured at checkout. The order moves straight to paid/confirmed.
  The existing capture-on-confirmation listener simply finds nothing to capture (no-op) — this is correct.
  Refund-on-cancellation still works (it refunds the captured payment).
- **AUTHORIZATION:** full parity with the APS hold→capture flow (capture on order confirmation, void/refund
  on cancellation). **Requires Moyasar "manual capture" to be enabled on your account** — confirm this with
  Moyasar before switching, otherwise authorization payments may be auto-captured or rejected.
  See [§9](#9-must-confirm-before-go-live-do-not-skip).

---

## 6. Switch the active gateway (admin)

The active gateway is stored in the DB (`admin_settings` key `active_payment_gateway`) and read at runtime
on every payment initiation. No deploy needed.

**Admin API (requires admin auth):**

```http
GET  /api/v1/admin/payment-gateways
# -> lists amazon_pay & moyasar with: registered, enabled, configured, test_mode, is_active

PUT  /api/v1/admin/payment-gateways/active
Content-Type: application/json
{ "gateway": "moyasar" }     # or "amazon_pay"
```

- The switch is **rejected** (HTTP 422) if the chosen gateway isn't registered or isn't fully configured
  (missing secret key) — so you can't accidentally activate an unconfigured gateway.
- To switch back: `{ "gateway": "amazon_pay" }`.
- Default (no setting saved): falls back to `PAYMENT_GATEWAY` in `.env` (currently `amazon_pay`).

---

## 7. End-to-end test plan (do this in sandbox first)

1. `MOYASAR_TEST_MODE=true`, fill `MOYASAR_TEST_SECRET_KEY` + `MOYASAR_TEST_WEBHOOK_SECRET`, `config:clear`.
2. `GET /api/v1/admin/payment-gateways` → confirm `moyasar` shows `configured: true`.
3. `PUT /api/v1/admin/payment-gateways/active { "gateway": "moyasar" }`.
4. **Order checkout:** create/confirm an order, choose a card method, complete payment on Moyasar's hosted
   page with a Moyasar **test card** → you should be redirected back and the order should become paid.
5. **Webhook:** confirm the webhook hit `…/payments/moyasar/webhook` and the transaction is `completed`
   (check `storage/logs/payment-YYYY-MM-DD.log` — all gateway traffic is logged there with secrets masked).
6. **Wallet deposit:** top up the wallet via Moyasar → balance credited once (not twice, even with redirect + webhook).
7. **Refund:** cancel the order (or `POST /api/v1/admin/orders/{id}/payments/refund`) → confirm a refund is created.
8. Repeat with `MOYASAR_TEST_MODE=false` + live keys + a small real transaction before full go-live.

---

## 8. Where everything lives (for reference)

| Piece | Location |
|---|---|
| Gateway implementation | `Modules/Payment/app/Gateways/MoyasarGateway.php` |
| Active-gateway resolver | `Modules/Payment/app/Services/ActiveGatewayResolver.php` |
| Admin switch controller | `Modules/Admin/app/Http/Controllers/AdminPaymentGatewayController.php` |
| Admin routes | `Modules/Admin/routes/api.php` (`payment-gateways`) |
| Webhook handler + route | `PaymentController::moyasarWebhook` + `Modules/Payment/routes/api.php` |
| Config | `config/payment.php` (`gateways.moyasar`) + `config/payment_credentials.php` |
| Env template | `.env.example` (Moyasar block) |
| Gateway logs | `storage/logs/payment-YYYY-MM-DD.log` (channel `payment`) |

---

## 9. ⚠️ MUST CONFIRM before go-live (do not skip)

These are Moyasar API details that were implemented against the **documented/standard** behaviour but
**could not be verified live** during development. Confirm each against Moyasar's official docs / dashboard
and adjust the small, clearly-marked spots in `MoyasarGateway.php` / `config/payment.php` if needed.
Each is tagged `CONFIRM:` in the code.

1. **Hosted-page / redirect flow.** The integration uses the **Invoice API** (`POST /v1/invoices`, redirect to
   the returned `url`). Confirm this is the flow you want, and that the redirect field is `callback_url`
   (some setups use `success_url` / `back_url`). _If your account uses a different field, set it in the dashboard
   or adjust `MoyasarGateway::initializePayment`._
2. **Redirect parameter on return.** We read the Moyasar payment/invoice id from the `id` query param and
   resolve it (payment first, invoice fallback). Confirm Moyasar appends `id` (and `status`) and **preserves**
   the query string we add (`merchant_reference`, `order_id`, etc.). _If it strips them, the server webhook still
   settles the payment, but verify._
3. **Webhook signature scheme.** We accept either a shared `secret_token` echoed in the webhook body **or** an
   HMAC-SHA256 signature header. Confirm which scheme Moyasar uses for your account and the exact header name,
   then keep only that path if you want strict verification. (`MoyasarGateway::verifyWebhook`.)
4. **Webhook payload shape.** We read the payment object at `data` and metadata at `data.metadata`. Confirm the
   webhook body shape and that the `metadata` you set at creation (incl. `merchant_reference`) is echoed back.
5. **Manual capture (AUTHORIZATION mode).** Confirm Moyasar manual capture is enabled on your account and the
   exact field to request a hold on an **invoice** (`manual: true` is assumed). Keep `MOYASAR_COMMAND=PURCHASE`
   until confirmed.
6. **Capture / void / refund endpoints.** Assumed `POST /v1/payments/{id}/capture`, `/void`, `/refund` with
   `amount` in halalas (omit/zero for full). Confirm endpoint paths and partial-amount semantics.
7. **Refund timing.** Refunds may be **asynchronous** (accepted now, finalized via a later `payment_refunded`
   webhook). We mark an accepted refund as success; confirm whether you also want to reconcile on the webhook.
8. **Sources & metadata limits.** Confirm which payment sources (`creditcard`, `mada`, `stcpay`, `applepay`, …)
   are enabled on your account, and Moyasar's metadata key/size limits (we keep metadata lean).
9. **IP allow-listing.** Moyasar does not generally require IP whitelisting for inbound webhooks, but if your
   firewall restricts inbound traffic, allow Moyasar's webhook source IPs (get the current list from Moyasar support)
   and ensure outbound HTTPS to `api.moyasar.com` is open from the app server.

---

## 10. Rollback

To revert to Amazon Pay at any time (no deploy): `PUT /api/v1/admin/payment-gateways/active { "gateway": "amazon_pay" }`.
Moyasar stays registered and ready; you can switch back and forth freely. Transactions already created on a gateway
always settle on that same gateway.
