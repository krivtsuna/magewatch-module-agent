# MageWatch Agent for Magento 2

Read-only monitoring agent for **Magento 2.4.6+**. Collects indexers, cron, queues, orders, logs, and system metrics every 5 minutes and POSTs a JSON heartbeat to [MageWatch](https://magewatch.io).

- Packagist: [magewatch/module-agent](https://packagist.org/packages/magewatch/module-agent)
- Source: [github.com/krivtsuna/magewatch-module-agent](https://github.com/krivtsuna/magewatch-module-agent)

## Requirements

- Magento 2.4.6+ (Open Source or Adobe Commerce)
- PHP 8.1, 8.2, or 8.3

## Installation

### From Packagist (recommended)

From your Magento project root:

```bash
composer require magewatch/module-agent
bin/magento module:enable MageWatch_Agent
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Before Packagist indexes the repo (or for private mirrors)

Add the GitHub VCS repository to your Magento `composer.json`, then require the package:

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

### Local monorepo development (path repository)

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

## Configuration

**Stores → Configuration → MageWatch → Agent**

| Field | Description |
|---|---|
| Enabled | Turns the cron job on/off |
| API Endpoint URL | HTTPS ingest URL, e.g. `https://magewatch.io/api/v1/ingest` |
| Site Token | Bearer token from the MageWatch dashboard |
| Stuck Cron Threshold | Minutes before a running job is reported as stuck (default 30) |

## CLI

```bash
bin/magento magewatch:status
bin/magento magewatch:send
bin/magento magewatch:send --force
```

## How it works

- Cron group `magewatch` runs `CollectAndSend` on a configurable schedule (default `*/5 * * * *`).
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

## Uninstall

```bash
bin/magento module:disable MageWatch_Agent
composer remove magewatch/module-agent
bin/magento setup:upgrade
```

## License

OSL-3.0 — see [LICENSE](LICENSE).
