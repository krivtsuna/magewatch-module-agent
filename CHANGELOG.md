# Changelog

All notable changes to `magewatch/module-agent` are documented here.
Version numbers follow [SemVer](https://semver.org/). Packagist reads versions from Git tags.

## [1.2.35] - 2026-09-14

### Changed

- **Fulfillment tracks:** first collect is the last 14 days only. Later heartbeats send that window so new shipments appear without pulling 90-day Magento history.

[1.2.35]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.35

## [1.2.34] - 2026-09-12

### Changed

- **Fulfillment tracks:** include Magento shipping `telephone` as `ship_phone` for carrier lookup forms. Still no name, email, or street.

[1.2.34]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.34

## [1.2.33] - 2026-09-12

### Changed

- **Fulfillment tracks:** include Magento shipping `region`, `city`, `country_id`, and `postcode`. Still no name, email, phone, or street.

[1.2.33]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.33

## [1.2.32] - 2026-09-11

### Fixed

- **Compiled DI:** `PayloadBuilder` no longer defaults `CollectorCadence` with `new`. Magento `setup:di:compile` was `var_export`ing that instance and calling missing `__set_state()`, which fataled storefront, admin, and `bin/magento`. Cadence is injected; `__set_state()` is also present as a safety net. TTL unchanged.
- **Cookie restriction:** `isCookieRestrictionModeEnabled()` reads store scope via `Magento\Store\Model\ScopeInterface::SCOPE_STORE`. `ScopeConfigInterface::SCOPE_TYPE_STORE` does not exist and fatals the storefront.

[1.2.32]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.32

## [1.2.31] - 2026-09-11

### Changed

- **Bestsellers:** prefer Magento `sales_bestsellers_aggregated_daily` and cache the top-SKU list for 1 hour. Stock check on that list still runs every heartbeat. Raw `sales_order_item` GROUP BY is the fallback only.
- **cron_schedule:** `last_success` per group is `MAX(finished_at)` with a job_code filter — no 2000-row PHP scan. Row count uses `information_schema.TABLE_ROWS` instead of `COUNT(*)`.
- **CMS integrity:** full `cms_block` / `cms_page` walk at most once an hour. Every heartbeat still rescans rows with `update_time` in the last 2 hours. `core_config_data` stays live.
- **Heartbeat cadence:** `catalog_health` and `composer` collect live every 60 minutes, `report` every 15. Last payload section is reused so SaaS snapshots stay complete. `magewatch:send` and Test Connection force a refresh.

[1.2.31]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.31

## [1.2.30] - 2026-09-11

### Fixed

- **pub/media walk:** the 5-minute security scan no longer recursively stats product image trees (`media/catalog/product`, `media/catalog/category`), `pub/static`, or cache/captcha dumps. PHP drops in `pub/`, `pub/media/*.php`, and `wysiwyg` are still reported. Hard cap: 2500 inodes, 80 PHP files, depth 8.

[1.2.30]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.30

## [1.2.29] - 2026-09-10

### Added

- **Fulfillment tracks:** collector `fulfillment` sends open Magento `sales_shipment_track` rows (increment ID, tracking number, Magento carrier code/title, order total). No Packlink, no customer PII. SaaS resolves the underlying carrier and polls tracking once a day.

[1.2.29]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.29

## [1.2.28] - 2026-09-10

### Added

- **Order campaign attribution:** storefront script writes a first-party `mw_attr` cookie (UTM, click IDs, referrer) so Varnish/FPC cannot strip the landing tags. On `checkout_submit_all_after` the agent stores first-touch and last-touch against the order in `magewatch_order_attribution`. The new `order_attribution` collector sends 7-day aggregates (day × source/medium/campaign → orders + revenue) — no increment IDs or click IDs leave the store. Cookie lifetime default 30 days; respects Magento cookie restriction, GPC, Cookiebot and OneTrust.

[1.2.28]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.28

## [1.2.27] - 2026-08-14

### Added

- **Isolated patch needles:** `security_patch_checks[].marker_contains` verifies unique snippets inside existing Magento files, so monthly isolated patches that only edit vendor (no new marker files) can be proven applied or missing.

[1.2.27]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.27

## [1.2.26] - 2026-07-29

### Fixed

- **RUM cache bust (`?v=4`):** load SaaS RUM v1.7 so session funnel stages are not blocked by the 2.5s aggregate fallback race.

[1.2.26]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.26

## [1.2.25] - 2026-07-28

### Fixed

- **Catalog health:** count missing images/prices only for enabled + catalog-visible products (base `image` attribute), and include sample SKUs — avoids false “641 missing images” from disabled / Not Visible Individually children.

[1.2.25]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.25

## [1.2.24] - 2026-07-28

### Added

- **Catalog health collector:** aggregate buyability signals for SaaS Product Revenue Leaks — missing price/image counts, configurables without options, and out-of-stock bestseller SKUs (no PII).

[1.2.24]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.24

## [1.2.23] - 2026-07-27

### Fixed

- **DI compile:** remove `new ProbeBlockDetector` constructor default — Magento compiled metadata cannot `__set_state()` that object and broke `bin/magento` after upgrade.

[1.2.23]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.23

## [1.2.22] - 2026-07-27

### Added

- **Storefront probe evidence:** on failed homepage probes, send `homepage_block_kind`, a short `homepage_body_excerpt` (e.g. Cloudflare “Just a moment…”), and `homepage_cf_ray` so MageWatch can show the block text and ask merchants to allowlist the probe IP.

[1.2.22]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.22

## [1.2.21] - 2026-07-27

### Fixed

- **Admin 2FA:** when `tfa_user_config` is missing (TwoFactorAuth module disabled), count all active admins as without 2FA instead of reporting `0`. Also treat empty `encoded_config` as unenrolled; support legacy `msp_tfa_user_config`. Payload adds `tfa_available`.

[1.2.21]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.21

## [1.2.20] - 2026-07-27

### Changed

- **Docs:** refresh README/AGENT.md and republish as a new Packagist version after tag 1.2.19 was restored for immutability (no functional agent changes).

[1.2.20]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.20

## [1.2.19] - 2026-07-27

### Added

- **Content integrity:** scan `core_config_data` HTML paths + CMS for Magecart-style obfuscated JS (`security.content_integrity`).
- **Admin security:** failed logins (24h), locked accounts, users without 2FA (`security.admin_security`).
- **Config hygiene:** template hints / minify / signing / rewrites / async email (`security.config_hygiene`).
- **Health rollup:** top-level `health.status` + `health.checks` with Tier-1 vs Tier-2 capping (operational critical → degraded for overall only).
- **Sync-on-connect:** Test Connection verifies ping then ships a full snapshot immediately (`sync_on_connect`).

[1.2.19]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.19

## [1.2.18] - 2026-07-23

### Added

- **Cron errors:** group by `job_code` + `messages` and include a truncated `message` on each error row so identical failures collapse with a count.

### Changed

- Missed/error job lists are sorted by count (desc) and capped at 40 rows.

[1.2.18]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.18

## [1.2.17] - 2026-07-23

### Changed

- **License:** switched from OSL-3.0 to a proprietary source-available licence (active MageWatch account required).

[1.2.17]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.17

## [1.2.16] - 2026-07-15

### Changed

- **Storefront probe:** homepage only — guest `/checkout` URL probe removed. Checkout health on MageWatch SaaS uses RUM funnel and order volume instead.

[1.2.16]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.16

## [1.2.15] - 2026-07-15

### Added

- **Remote-config patch fingerprints:** `magento.patch_verification` checks marker files from MageWatch SaaS `security_patch_checks` — isolated APSB coverage without relying on `vendor/bin/patch-status`.

### Fixed

- **HTTP 100 false failure on Nexcess/proxied hosts:** send `Expect:` header to avoid `Delivery failed (HTTP 100): {"status":"ok"}` when ingest succeeded.

[1.2.15]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.15

## [1.2.14] - 2026-07-15

### Added

- **Adobe patch-status in heartbeat:** `magento.patch_status` from `vendor/bin/patch-status` when present — lets MageWatch verify isolated APSB patches after monthly security releases.

[1.2.14]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.14

## [1.2.13] - 2026-07-14

### Fixed

- **Instant maintenance ping on Magento 2.4+:** hook `MaintenanceMode::set()` instead of removed `enable()`/`disable()` methods — `bin/magento maintenance:enable|disable` now notifies MageWatch immediately.

[1.2.13]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.13

## [1.2.12] - 2026-07-14

### Added

- **Instant maintenance pings:** plugin on `MaintenanceMode::enable/disable` sends a heartbeat to MageWatch immediately when you run `bin/magento maintenance:enable|disable` — no need to wait for the minute cron.

[1.2.12]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.12

## [1.2.11] - 2026-07-14

### Added

- **Immediate maintenance detection:** heartbeat pings include `maintenance_mode`; on state change the agent sends a ping right away (bypasses the 1-minute throttle) so MageWatch can notify without waiting for the next cron tick.

[1.2.11]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.11

## [1.2.10] - 2026-07-13

### Fixed

- **RUM on strict CSP checkout:** no inline `window.__mwRum`; pass site-wide `k/u` as query params on the external script URL.
- **FPC-safe snippet:** block stays `cacheable="true"` — page type is detected client-side in SaaS `v1.js` (URL + body class), so output is identical on every page.
- **RUM loader race:** `defer` instead of `async`; SaaS `v1.js` retries until `k` is readable.
- **Checkout layout:** explicit `checkout_*` layout handles so the snippet is present on cart/checkout/success.

[1.2.10]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.10

## [1.2.9] - 2026-07-13

### Fixed

- **RUM snippet FPC:** `cacheable="false"` so `window.__mwRum.p` is not shared across cached pages.
- **RUM script cache bust:** load `/rum/v1.js?v=2` after SaaS funnel fixes ship.

[1.2.9]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.9

## [1.2.8] - 2026-07-13

### Added

- **Store locale:** `magento.base_currency_code` and `magento.timezone` from store config in heartbeats.
- **Cron groups:** `cron.groups[]` with per-group last success, missed 1h, errors, and stuck counts.

[1.2.8]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.8

## [1.2.7] - 2026-07-13

### Fixed

- **RUM checkout funnel:** detect checkout/success from URL path when layout handles are generic (Hyvä, Amasty, CMS-wrapped checkout).
- **Checkout probe:** treat empty-cart redirect (`/checkout` → `/checkout/cart`) as healthy instead of a storefront outage.

[1.2.7]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.7

## [1.2.6] - 2026-07-13

### Fixed

- **Log bootstrap:** on first agent run, read only the last ~1 MiB of each log file (not the whole file). Active logs with a recent `mtime` no longer dump tens of thousands of historical exception lines into the first heartbeat.

[1.2.6]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.6

## [1.2.5] - 2026-07-10

### Added

- **Split heartbeat:** `magewatch_heartbeat_ping` (`*/1`) sends a minimal payload for paid minute-level visibility; `magewatch_collect_and_send` (`*/5`) runs full collectors.
- **`HeartbeatDelivery`:** shared delivery service with separate ping/full throttle caches (free plan stays hourly).

### Fixed

- Paid stores get minute `last_seen` updates without running heavy collectors every minute — survives busy `cron_schedule` queues better than a single `*/1` full job.

[1.2.5]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.5

## [1.2.4] - 2026-07-10

### Fixed

- **Cron reliability:** revert schedule to `*/5 * * * *` (was `*/1`) — reduces `cron_schedule` pressure and missed-job churn on busy stores.
- **Isolation:** `magewatch` cron group runs in a separate PHP process (`use_separate_process=1`) so default-group backlog does not block heartbeats.

[1.2.4]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.4

## [1.2.3] - 2026-07-10

### Fixed

- **Test ping:** send a lightweight payload (no storefront probes / pub/ scans) so the admin button returns in seconds, not minutes.
- AJAX timeout with a clear error after 30 seconds.

[1.2.3]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.3

## [1.2.2] - 2026-07-10

### Fixed

- Declare PHP 8.4 compatibility in `composer.json` (Magento 2.4.8+).

[1.2.2]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.2

## [1.2.1] - 2026-07-10

### Fixed

- **Security collector:** instantiate `PubPhpIntegrityChecker` directly — fixes `Too few arguments to function SecurityCollector::__construct()` on stores with compiled DI from 1.2.0.

[1.2.1]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.1

## [1.2.0] - 2026-07-10

### Fixed

- **Security collector:** whitelist legitimate Magento `pub/` PHP files (`cron.php`, `errors/**`, etc.) and compare content hashes against `vendor/magento/magento2-base` — `pub/cron.php` no longer false-positives as unexpected PHP.
- Emit `core_pub_php_modified` when a known core filename differs from the vendor baseline.
- **Deploy fingerprint:** report `static_version` and `composer_lock_hash` in heartbeat for SaaS deploy correlation.

[1.2.0]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.2.0

## [1.1.1] - 2026-07-10

### Fixed

- **CSP:** whitelist `https://magewatch.io` for `script-src` (RUM loader) and `connect-src` (ingest beacons) via `etc/csp_whitelist.xml`.
- RUM config inline script uses `SecureHtmlRenderer` nonce when Magento CSP is enabled.

[1.1.1]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.1.1

## [1.1.0] - 2026-07-10

### Added

- **Frontend monitoring (RUM):** cache-safe storefront snippet injection via `view/frontend/layout/default.xml`.
- Admin toggle: Stores → Config → MageWatch → Frontend monitoring (RUM).
- Consumes `rum_public_key` and `rum_enabled` from SaaS remote config — no manual key setup.

[1.1.0]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.1.0

## [1.0.15] - 2026-07-10

### Changed

- Cron runs every minute (`*/1 * * * *`); paid plans send each run via remote `heartbeat_interval_minutes: 1`, free plans throttle to hourly.

[1.0.15]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.15

## [1.0.14] - 2026-07-10

### Changed

- Cron schedule aligned to Phase 1 spec: `*/5 * * * *` (every 5 minutes).
- `magewatch:status` lists all registered collectors from `CollectorPool`, not a hardcoded subset.

### Added

- Unit tests for `QueueCollector`, `OrderStatsCollector`, `LogCollector`, and `SystemCollector`.
- `docs/AGENT.md` — file tree and payload schema reference.

[1.0.14]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.14

## [1.0.13] - 2026-07-09

### Added

- **Database collector** — MySQL `SHOW GLOBAL STATUS` (threads, slow_queries counter) and long-running InnoDB transactions via `information_schema`.
- **Inode usage** in the system collector (`inode_free_percent`) when `df -i` is available on the host.

[1.0.13]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.13

## [1.0.12] - 2026-07-09

### Changed

- Clarified API endpoint admin help text: production URL default, Docker notes moved to MageWatch docs.

[1.0.12]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.12

## [1.0.11] - 2026-07-09

### Added

- Infrastructure collector probes Redis cache backend reachability and OpenSearch/Elasticsearch cluster health (`infrastructure.redis`, `infrastructure.search`).

[1.0.11]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.11

## [1.0.10] - 2026-07-09

### Added

- Report count of orders stuck in `pending_payment` for over 2 hours (`orders.pending_payment_stuck`).

[1.0.10]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.10

## [1.0.9] - 2026-07-09

### Added

- Read Mirasvit-style `version.json` and `@version` from `registration.php` when `composer.json` / `setup_version` are missing.

[1.0.9]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.9

## [1.0.8] - 2026-07-09

### Added

- Composer collector prioritizes `composer.lock` packages for **enabled third-party modules** (name candidates + vendor prefixes) instead of only the first 400 alphabetical entries.
- Heartbeat includes `modules.installed` with version and Composer package name from each module's `composer.json` or `module.xml` (covers `app/code` modules).

### Fixed

- Module-to-Composer package name resolution for vendors like Amasty (`amasty/advanced-review` vs `amasty/module-advancedreview`).
- Exception log parser extracts JSON and Report ID messages for clearer MageWatch alerts.

[1.0.8]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.8

## [1.0.0] - 2026-07-07

### Added

- Initial public release for Packagist.
- Collectors: indexer, cron, queue, order stats, logs, system, security, composer metadata.
- Cron group `magewatch` with configurable schedule (default every 5 minutes).
- Remote config sync from MageWatch `/api/v1/config`.
- CLI: `magewatch:status`, `magewatch:send`.
- Admin: Stores → Configuration → MageWatch → Agent, Send Test Ping.
- Encrypted site token storage, dedicated `magewatch.log`, DB-backed log offsets.

[1.0.0]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.0

## [1.0.2] - 2026-07-08

### Added

- Log collector now tails `var/log/payment.log` alongside exception and system logs.
- Heartbeat includes `payment_new_lines`, `payment_log_bytes`, and `recent_payment_errors` (declines, gateway failures, ERROR/CRITICAL lines).
- Order stats collector now reports **7 days** of hourly orders (was 24 hours) so the dashboard chart matches the UI.

## [1.0.1] - 2026-07-08

### Changed

- Log collector: on first sight of a log file, skip content older than ~7 days instead of reading from byte zero. Subsequent heartbeats only read new lines since the stored offset (unchanged cap: 5 MB per run).
- Log collector reports `exception_log_bytes` and `system_log_bytes` in each heartbeat for MageWatch size warnings.

[1.0.2]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.2

## [1.0.7] - 2026-07-08

### Added

- Origin storefront probe detects Magento generic error pages in the HTML response and reports `homepage_magento_error` / `checkout_magento_error` in each heartbeat.

[1.0.7]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.7
[1.0.6]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.6

## [1.0.5] - 2026-07-08

### Fixed

- Indexer backlog now matches `bin/magento indexer:status` (distinct pending entities, not raw changelog row count).
- Disabled indexers (e.g. legacy flat indexers) are omitted from the heartbeat list.

[1.0.5]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.5

## [1.0.4] - 2026-07-08

### Added

- **Error report collector** — reads the newest files from `var/report` (Magento exception report dumps) and sends message, URL, and class in each heartbeat.

[1.0.4]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.4

## [1.0.3] - 2026-07-08

### Added

- **Storefront probe collector** — checks homepage and `/checkout` from the Magento server via `127.0.0.1` (origin), so MageWatch can tell real outages from Cloudflare/WAF blocks on external monitors. No client IP whitelist required.

[1.0.3]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.3
[1.0.1]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.1
[1.0.0]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.0
