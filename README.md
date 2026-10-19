# SilverShop Stock Alerts

Back-in-stock ("email me when it's back") notifications for [SilverShop](https://github.com/silvershop/silvershop-core),
for **Silverstripe CMS 6**.

A customer subscribes to an out-of-stock product or variation with just an email address. The alert is confirmed by a
tokenised double-opt-in link (guests) or armed immediately (logged-in members). The moment the item is back in stock,
one email goes out and the alert flips to *Notified* — one shot, no spam.

Back-in-stock is v1. Price-drop alerts are planned for v2 (the model already carries the `PriceDrop` type and a
`SubscribedPrice` baseline).

## How it works

- **Subscribe** — the storefront buy box / sticky bar shows a *"Notify me when it's back"* button on a sold-out
  product; it discloses an inline email form that POSTs to `stock-alert/subscribe`. Guests get a confirm email;
  members are armed straight away.
- **Trigger** — a `ProductWarehouseStockExtension::onAfterWrite()` watches every `silvershop/stock` stock-row write and,
  on a genuine out-of-stock → available flip, enqueues an instant `NotifyBackInStockJob`. A recurring
  `ScanStockAlertsJob` is the safety-net for anything a direct write/import misses, and `SendStockAlertsTask` is the
  CLI runner over the same service.
- **Send** — the dispatch gathers the *Verified* waiters for the buyable (variation-level **and** product-level),
  claims each row atomically (flips it to *Notified* before sending, so a retried job never double-sends) and emails.

## Requirements

- PHP ^8.3
- silverstripe/framework ^6.0
- silvershop/core ^6
- symbiote/silverstripe-queuedjobs ^6.0

Back-in-stock alerts need **silvershop/stock** (suggested): the trigger watches its stock rows, so the feature stays
dormant — no notify UI, no trigger — until it is installed. The storefront UI ships with **silvershop/storefront**.

## Installation

```bash
composer require silvershop/stock-alerts
```

Then run `dev/build?flush=all`.

## Configuration

Set on `SilverShop\StockAlerts\Service\StockAlertService` (see `_config/stock-alerts.yml`):

| Setting | Default | Purpose |
|---|---|---|
| `back_in_stock_enabled` | `true` | Master on/off for back-in-stock alerts. |
| `double_opt_in` | `true` | Guests must confirm via email before the alert is armed. |
| `member_auto_confirm` | `true` | Logged-in members are armed immediately (no confirm email). |
| `verify_ttl_days` | `7` | Unconfirmed alerts older than this are expired by the scan. |
| `renotify_window_mins` | `60` | Suppress a second send to the same alert within this window. |
| `max_per_run` | `100` | Cap on emails sent per dispatch run (throttle a hot restock). |
| `from_email` | `''` | Sender address (falls back to store-profile / SiteConfig / admin email). |

## Routes

| Route | Purpose |
|---|---|
| `stock-alert/subscribe` | POST — subscribe to a back-in-stock alert (CSRF-protected). |
| `stock-alert/confirm/$Token` | One-click double-opt-in confirm. |
| `stock-alert/unsubscribe/$Token` | One-click unsubscribe. |

## Sending

Instant sends go through the queue (`NotifyBackInStockJob`), visible in **Jobs** (QueuedJobAdmin). Without a job
worker, run the backstop from cron:

```bash
# list what is waiting (dry run)
vendor/bin/sake tasks:SendStockAlertsTask
# re-check availability and send
vendor/bin/sake tasks:SendStockAlertsTask --run
# arm the recurring scan job
vendor/bin/sake tasks:SendStockAlertsTask --schedule
```

## License

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
