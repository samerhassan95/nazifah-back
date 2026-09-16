# Auto-Expire Stale Unpaid Order-Edit Surcharges

## The problem
When a client edits an order and the price goes up, and they choose to
pay the difference by card, the edit is **staged** behind a
`OrderModificationIntent` until that surcharge payment actually
completes — the item/price change itself isn't applied yet. If the
client never finishes paying (insufficient balance, closed the payment
page, abandoned checkout), that surcharge leg just sits there forever
with status `pending`, showing up in `payment_breakdown` indefinitely
as if it's still awaiting the client — with no way to clear it.

## What changed
A new scheduled command runs every 5 minutes and cancels any
modification intent that's been sitting `pending` for more than 5
minutes:

- Its unpaid surcharge leg(s) are marked `cancelled` (a wallet leg also
  gets its wallet hold released via the existing
  `releaseReservedWalletLeg()`).
- The modification intent itself is marked `expired`.
- The order itself needs **no changes** — the staged item/price change
  was never applied in the first place (that only happens once the
  surcharge is fully paid), so there's nothing to roll back.

Once a leg is `cancelled`, it no longer appears in `payment_breakdown`
(that endpoint already excludes cancelled/failed legs) — so the stray
"pending card payment" row disappears on its own.

## Command
```
php artisan orders:expire-stale-modification-intents [--minutes=5] [--dry-run]
```
- `--minutes` — age threshold (default 5).
- `--dry-run` — list what would be expired without making changes.

Scheduled every 5 minutes in `OrderServiceProvider::registerCommandSchedules()`,
the same mechanism the existing `orders:release-expired-wallet-holds`
command already uses (`everyFiveMinutes()->withoutOverlapping()->runInBackground()`).
This depends on the server's cron actually invoking `php artisan schedule:run`
every minute (Laravel's standard scheduler cron entry) — since the older
wallet-hold command already runs this way, that entry should already be
in place; worth a quick check on the crontab if the new job doesn't seem
to fire.

## Manual test (no waiting required)
```bash
php artisan orders:expire-stale-modification-intents --dry-run
```
Lists every currently-stale intent without changing anything. Drop
`--dry-run` to actually expire them right now instead of waiting for
the schedule.

## Files changed
- `Modules/Order/app/Services/OrderPaymentService.php` — new
  `expireStaleModificationIntent()`
- `Modules/Order/app/Console/Commands/ExpireStaleOrderModificationIntents.php` (new)
- `Modules/Order/app/Providers/OrderServiceProvider.php` — command
  registration + schedule

## Commits
- `fef8028` — initial feature (15-minute default)
- `8ea8b13` — lowered default threshold to 5 minutes
