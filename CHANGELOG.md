# Changelog

All notable changes to `magewatch/module-agent` are documented here.
Version numbers follow [SemVer](https://semver.org/). Packagist reads versions from Git tags.

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
