# Contributing — MageWatch Agent

Development notes for maintainers. This file is **not** shown on Packagist — the customer-facing README is `README.md`.

## Local monorepo (path repository)

From a Magento project that sits next to the MageWatch SaaS repo:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../magewatch.io/packages/magewatch-module-agent",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "magewatch/module-agent": "@dev"
    }
}
```

```bash
composer update magewatch/module-agent
bin/magento setup:upgrade
bin/magento cache:flush
```

## Before Packagist indexes a new GitHub release

Add the VCS repository to Magento `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/krivtsuna/magewatch-module-agent"
        }
    ]
}
```

```bash
composer require magewatch/module-agent:^1.0
bin/magento module:enable MageWatch_Agent
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration reference

**Stores → Configuration → MageWatch → Agent**

| Field | Description |
|---|---|
| Enabled | Turns the cron job on/off |
| API Endpoint URL | HTTPS ingest URL, e.g. `https://ingest.magewatch.io/api/v1/ingest` |
| Site Token | Bearer token from the MageWatch dashboard |
| Stuck Cron Threshold | Minutes before a running job is reported as stuck (default 30) |

## How it works

- Cron group `magewatch` runs `HeartbeatPing` every minute (`*/1`) and `CollectAndSend` every five minutes (`*/5`) in a **separate PHP process**. Paid plans: minute ping + 5‑minute full metrics (`heartbeat_interval_minutes: 1` from SaaS). Free: both throttled to hourly.
- Requires the same system cron Magento already needs: `* * * * * php bin/magento cron:run` — no extra crontab lines for MageWatch.
- Collectors implement `CollectorInterface`; failures are logged and listed in `collector_errors` without breaking the run.
- Delivery uses Magento's Curl client (TLS verification on, 5s connect / 10s total timeout).
- Log line deltas use the `magewatch_log_offset` DB table (multi-node safe).
- On first install, log reading starts at the last ~7 days (not byte zero of multi-GB files). Each heartbeat then reads only new appended lines (up to 5 MB per run until caught up).

## Tests

Unit tests run inside a Magento dev environment:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/MageWatch/Agent/Test/Unit
```

In this standalone repo (requires Magento packages in `vendor/`):

```bash
composer install
vendor/bin/phpunit
```

## Publishing

From the MageWatch SaaS monorepo root:

```bash
./scripts/publish-agent-package.sh
```

See `docs/PACKAGIST.md` in the SaaS repository.
