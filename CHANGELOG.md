# Changelog

All notable changes to `magewatch/module-agent` are documented here.
Version numbers follow [SemVer](https://semver.org/). Packagist reads versions from Git tags.

## [1.0.0] - 2026-07-07

### Added

- Initial public release for Packagist.
- Collectors: indexer, cron, queue, order stats, logs, system, security, composer metadata.
- Cron group `magewatch` with configurable schedule (default every 5 minutes).
- Remote config sync from MageWatch `/api/v1/config`.
- CLI: `magewatch:status`, `magewatch:send`.
- Admin: Stores → Configuration → MageWatch → Agent, Send Test Ping.
- Encrypted site token storage, dedicated `magewatch.log`, DB-backed log offsets.

[1.0.1]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.1
[1.0.0]: https://github.com/krivtsuna/magewatch-module-agent/releases/tag/1.0.0

## [1.0.1] - 2026-07-08

### Changed

- Log collector: on first sight of a log file, skip content older than ~7 days instead of reading from byte zero. Subsequent heartbeats only read new lines since the stored offset (unchanged cap: 5 MB per run).
- Log collector reports `exception_log_bytes` and `system_log_bytes` in each heartbeat for MageWatch size warnings.
