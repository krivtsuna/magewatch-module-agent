# MageWatch Agent for Magento 2

Read-only monitoring agent for **Magento 2.4.x** (Open Source or Adobe Commerce). Every five minutes it collects health metrics — indexers, cron, queues, order aggregates, log signals, system resources, and security hygiene — and pushes a JSON heartbeat to [MageWatch](https://magewatch.io) over HTTPS.

Packagist: [magewatch/module-agent](https://packagist.org/packages/magewatch/module-agent)

## What it is

The MageWatch agent runs inside your Magento store. It does not modify catalog, sales, or customer data. It reports operational signals so your agency dashboard can alert you when indexers stall, crons miss runs, queues back up, orders drop unexpectedly, or logs spike — before your client notices.

## What it does NOT do

- **Never writes** to catalog, sales, or customer tables — collectors are read-only.
- **No customer PII** — order data is hourly aggregates only (counts and revenue buckets), not individual orders or buyer details.
- **No remote code execution** — the agent only pushes JSON over HTTPS to your MageWatch ingest endpoint. There is no inbound control channel.
- **Open source** — every collector is plain PHP under `Model/Collector/`. Inspect the code before you install on production.

## Install

From your Magento project root:

```bash
composer require magewatch/module-agent
bin/magento setup:upgrade
```

Then in Magento admin: **Stores → Configuration → MageWatch → Agent** — paste the site token from your MageWatch dashboard and set the ingest URL (production: `https://magewatch.io/api/v1/ingest`).

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

From **v1.1.0**, the agent can inject a tiny storefront script (paid MageWatch plans) that reports JS errors, funnel activity counters, and Web Vitals to MageWatch. The script loads from `https://magewatch.io/rum/v1.js` — logic lives on the SaaS so fixes do not require module releases.

- **Toggle:** Stores → Configuration → MageWatch → Agent → Frontend monitoring (RUM) (default ON).
- **Keys:** `rum_public_key` is synced automatically via remote config — never paste it manually.
- **What it collects:** sanitized JS error messages, add-to-cart/checkout/success counters, LCP/CLS/INP — no cookies, no PII, no session IDs.
- **Disable:** set Frontend monitoring to No — removes injection without uninstalling the agent.

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

OSL-3.0 — see [LICENSE](LICENSE).
