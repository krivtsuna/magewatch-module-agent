# MageWatch Agent for Magento 2

**Most uptime tools only ask: is the homepage returning 200?**

This agent runs **inside Magento** and reports what those tools never see:

| Uptime ping says… | MageWatch agent reports… |
|-------------------|--------------------------|
| Site is “up” | Cron stopped 40 minutes ago |
| Site is “up” | `catalog_product_price` indexer invalid |
| Site is “up” | Order rate dropped to 0 vs baseline |
| Site is “up” | Queue consumers stuck / backlog growing |
| Site is “up” | Unexpected PHP under `pub/` or injected JS in DB HTML |

Example alert your agency dashboard can open with a playbook:

> **Critical · Orders anomaly** — hourly orders fell to **0** (expected **8–22**).  
> First step: check payment webhooks and `pending_payment` backlog.

Packagist: [magewatch/module-agent](https://packagist.org/packages/magewatch/module-agent) · Product: [magewatch.io](https://magewatch.io)

---

Read-only monitoring agent for **Magento 2.4.x** (Open Source or Adobe Commerce). A lightweight heartbeat runs every minute (paid) to confirm the store is alive; every five minutes it collects health metrics — indexers, cron, queues, order aggregates, log signals, system resources, and security hygiene (pub/ PHP, DB content integrity, admin/config checks) — and pushes JSON to MageWatch over HTTPS.

## What it is

The MageWatch agent runs inside your Magento store. It does not modify catalog, sales, or customer data. It reports operational signals so your agency dashboard can alert you when indexers stall, crons miss runs, queues back up, orders drop unexpectedly, or logs spike — before your client notices.

## What it does NOT do

- **Never modifies** catalog, sales, or customer tables — collectors are read-only with respect to store data (the agent may write its own operational state: log offsets and `magewatch_order_attribution` rows).
- **No customer PII** — no names, emails, phones, or addresses. Campaigns stay aggregated. Fulfillment sends increment ID, tracking number, carrier title, and order total so the dashboard can poll the real carrier.
- **No remote code execution** — the agent only pushes JSON over HTTPS to your MageWatch ingest endpoint. There is no inbound control channel.
- **Open source** — every collector is plain PHP under `Model/Collector/`. Inspect the code before you install on production.

## Install

From your Magento project root:

```bash
composer require magewatch/module-agent
bin/magento setup:upgrade
```

Then in Magento admin: **Stores → Configuration → MageWatch → Agent** — paste the site token from your MageWatch dashboard and set the ingest URL (production: `https://ingest.magewatch.io/api/v1/ingest`). Use **Send Test Ping** (1.2.19+) to verify connectivity and deliver the first full snapshot immediately.

Full step-by-step guide: [magewatch.io/docs/install](https://magewatch.io/docs/install)

Architecture and payload schema: [docs/AGENT.md](docs/AGENT.md) (maintainers).

## Requirements

- Magento **2.4.x** (tested on 2.4.6+)
- PHP **8.1**, **8.2**, or **8.3**
- Outbound **HTTPS** to your MageWatch API host (e.g. `api.magewatch.io` or your self-hosted ingest URL)
- Magento cron running (agent uses the `magewatch` cron group)

## Useful commands

```bash
bin/magento magewatch:status    # last run, enabled collectors, ingest URL
bin/magento magewatch:send      # send heartbeat now (respects schedule)
bin/magento magewatch:send --force
```

## Frontend monitoring (RUM)

From **v1.1.0**, the agent can inject a tiny storefront script (paid MageWatch plans) that reports JS errors, funnel activity counters, and Web Vitals to MageWatch. The script loads from the same host as your ingest URL (production: `https://ingest.magewatch.io/rum/v1.js`) — logic lives on the SaaS so fixes do not require module releases.

- **Toggle:** Stores → Configuration → MageWatch → Agent → Frontend monitoring (RUM) (default ON).
- **Keys:** `rum_public_key` is synced automatically via remote config — never paste it manually.
- **What it collects:** sanitized JS errors, privacy-safe tab-session funnel stages, safe checkout transport failures, and LCP/CLS/INP. It sets no cookies and collects no customer PII, form values, cart contents, or request/response bodies. Raw tab IDs are short-lived and hashed by MageWatch before storage.
- **Disable:** set Frontend monitoring to No — removes injection without uninstalling the agent.

## Order campaign attribution (v1.2.28)

The agent can remember which campaign a shopper came from and attach that to the order.

Landing tags never reach PHP on a default Magento + Varnish setup (the VCL strips `utm_*` / `gclid` / `fbclid`, and FPC often skips PHP on the first hit). So capture is a small **first-party cookie** (`mw_attr`), separate from RUM. RUM stays cookieless; this script only runs when attribution is enabled.

- **Toggle:** Stores → Configuration → MageWatch → Agent → Order campaign attribution (default ON, 30-day window).
- **What is stored in Magento:** one row per order with first-touch and last-touch source/medium/campaign (plus referrer host and click id). Checkout cannot fail if attribution fails.
- **What is sent to MageWatch:** 7-day aggregates only — orders and base-currency revenue by day × source/medium/campaign. No order numbers, no click IDs.
- **Consent:** respects Magento cookie restriction mode, Global Privacy Control, Cookiebot and OneTrust. No cookie → no row (that is not counted as `(direct)`).

## Security collector (v1.2.0)

The agent whitelists legitimate Magento `pub/` PHP files and compares content hashes against `vendor/magento/magento2-base` when available. `pub/cron.php` and other core files no longer false-positive as unexpected PHP; modified core files emit `core_pub_php_modified`. Deploy fingerprints (`static_version`, `composer_lock_hash`) are included in heartbeats for SaaS deploy correlation.

See [CHANGELOG.md](CHANGELOG.md) for release history.

## Uninstall

```bash
bin/magento module:disable MageWatch_Agent
composer remove magewatch/module-agent
bin/magento setup:upgrade
```

## Data & privacy

What the agent collects, how long MageWatch retains it, and DPA terms for agencies:

[magewatch.io/data](https://magewatch.io/data)

## License

Proprietary — see [LICENSE](LICENSE). Use requires an active MageWatch account.
