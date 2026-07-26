# Real-Time Chat (WebSockets)

Chat uses **Laravel Reverb** (or Pusher) for WebSocket real-time delivery. When a message is sent (by client, vendor, or driver), it is broadcast to the conversation channel so all participants receive it instantly.

---

## All variables the frontend needs

Copy this checklist into your app config (env + runtime).

### A) Environment (Vite / `.env` on the **frontend** build)

| Variable | Example | Used for |
|----------|---------|----------|
| `VITE_REVERB_APP_KEY` | `local` | Echo `key` (must match backend `REVERB_APP_KEY`) |
| `VITE_REVERB_HOST` | `localhost` | WebSocket host (device: use LAN IP or public host, not `localhost` on phone) |
| `VITE_REVERB_PORT` | `8080` | WebSocket port (local Reverb default) |
| `VITE_REVERB_SCHEME` | `http` or `https` | `forceTLS` = (`https`); use `https` in production with TLS |

Optional but recommended:

| Variable | Example | Used for |
|----------|---------|----------|
| `VITE_API_BASE_URL` | `https://back.nathefah.com` | REST API + **broadcasting auth** full URL |

### B) Runtime (from login + chat APIs — not in `.env`)

| Variable | Source | Used for |
|----------|--------|----------|
| **Auth token** | Login response (client / vendor employee / driver) | `Authorization: Bearer …` on Echo `auth.headers` and all API calls |
| **`conversation_id`** | Chat list / send message response (`conversation_id`) | Channel name: `conversation.{conversationId}` |
| **`authEndpoint` (full URL)** | `{VITE_API_BASE_URL}/broadcasting/auth` | Echo must POST here with Bearer token (same origin/CORS as API) |

### C) Echo constructor (fixed values)

| Key | Value |
|-----|-------|
| `broadcaster` | `'reverb'` |
| `enabledTransports` | `['ws', 'wss']` |
| Event to listen | `.message.sent` (leading dot) |
| Channel (Echo) | `Echo.private('conversation.' + conversationId)` |

### D) WebSocket event payload (`message.sent`)

Each incoming message object `e` has:

| Field | Type | Notes |
|-------|------|-------|
| `id` | string (UUID) | Message id |
| `conversation_id` | string (UUID) | Same as subscribed channel |
| `sender_type` | string | `client` \| `vendor` \| `driver` \| `admin` |
| `sender_id` | number | Sender’s id for that type |
| `message` | string | Text body |
| `type` | string | `text` \| `image` \| `file` |
| `file_url` | string \| null | Full URL if attachment |
| `is_read` | boolean | |
| `read_at` | string \| null | `Y-m-d H:i:s` |
| `created_at` | string | `Y-m-d H:i:s` |

### E) Who can subscribe (private channel)

Only **client**, **vendor employee**, or **driver** that belong to that conversation can subscribe. **Admin** is not authorized on `conversation.{id}` in `routes/channels.php` today — admin apps need polling or a backend change to add admin to the channel.

---

## Backend setup

Ensure `laravel/reverb` and `pusher/pusher-php-server` are installed (`composer require laravel/reverb pusher/pusher-php-server`). If you see "Class Pusher\Pusher not found", run `composer dump-autoload`.

### 1. Environment

In `.env` set:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local
REVERB_APP_KEY=local
REVERB_APP_SECRET=local
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

For production, use your Reverb server host/port and `REVERB_SCHEME=https` if using TLS.

### 2. Run Reverb (local)

```bash
php artisan reverb:start
```

Keep this running while developing. For production, run Reverb as a supervised process (e.g. systemd or your hosting’s process manager).

### 3. Queue worker

Broadcasts are queued. Run a queue worker:

```bash
php artisan queue:work
```

### 4. Auth endpoint

Private channel subscription is authorized via `POST /broadcasting/auth` with the same API token (Bearer) used for client, vendor, or driver. The app registers this route in `AppServiceProvider` with `auth:sanctum`.

## Frontend (Echo)

### 1. Install

```bash
npm install laravel-echo pusher-js
```

### 2. Configure Echo (Reverb)

Example (e.g. in `bootstrap.js` or your app entry):

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'local',
    wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
    wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT || 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            Authorization: 'Bearer ' + yourAuthToken, // client / vendor / driver token
            Accept: 'application/json',
        },
    },
});
```

Set `yourAuthToken` to the logged-in user’s API token (client, vendor, or driver).

### 3. Subscribe and listen

Subscribe to the **private** channel for a conversation and listen for the custom event name:

```javascript
const conversationId = '...'; // UUID from chat.conversation_id in order/API

window.Echo.private(`conversation.${conversationId}`)
    .listen('.message.sent', (e) => {
        // e contains: id, conversation_id, sender_type, sender_id, message, type, file_url, is_read, read_at, created_at
        console.log('New message', e);
        // Append e to the messages list in your UI
    });
```

Use the **leading dot** in `.message.sent` because the event is broadcast as a custom name (`broadcastAs(): 'message.sent'`).

### 4. Unsubscribe

When leaving the chat screen:

```javascript
window.Echo.leave(`conversation.${conversationId}`);
```

## Channel authorization

- **Channel:** `private-conversation.{conversationId}` (Echo adds the `private-` prefix).
- **Allowed:** The conversation’s **client** (Client model), **vendor** (VendorEmployee with matching `vendor_id`), or **driver** (Driver model with matching `driver_id`). Defined in `routes/channels.php`.

## Event payload (message.sent)

See **section D** above for the full field list (`sender_type` includes `admin` when admin sends).

Firebase is still used for compatibility; Reverb/Pusher and Firebase can run together.
