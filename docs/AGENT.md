# MageWatch Agent — architecture reference

Phase 1 MVP module (`MageWatch_Agent`, Composer package `magewatch/module-agent`). Read-only collectors run in the `magewatch` cron group: a **lightweight ping every minute** updates SaaS `last_seen`, and a **full collection every five minutes** assembles the JSON payload posted to the MageWatch ingest API.

## File tree

```
packages/magewatch-module-agent/
├── Api/CollectorInterface.php          # collect(): array, getCode(): string
├── Block/Adminhtml/System/Config/      # Test ping button block
├── Console/Command/                    # magewatch:status, magewatch:send
├── Controller/Adminhtml/Config/        # Test ping AJAX endpoint
├── Cron/CollectAndSend.php             # Full collection cron job (fail-safe)
├── Cron/HeartbeatPing.php              # Lightweight minute ping (fail-safe)
├── Logger/                             # var/log/magewatch.log
├── Model/
│   ├── Collector/                      # One class per metric domain
│   ├── CollectorPool.php               # DI registry of collectors
│   ├── Config.php                      # Scope config + remote overrides
│   ├── PayloadBuilder.php              # Merges collector output
│   ├── Transport/HttpClient.php        # HTTPS POST (Curl, Bearer token)
│   └── LogOffset/                      # DB-backed log file offsets
├── Test/Unit/                          # PHPUnit (mocked ResourceConnection)
├── etc/
│   ├── crontab.xml                     # magewatch group: ping */1 + full */5
│   ├── cron_groups.xml
│   ├── config.xml                      # Defaults
│   ├── adminhtml/system.xml            # Admin UI fields
│   ├── acl.xml
│   ├── di.xml                          # Collector registration
│   └── db_schema.xml                   # magewatch_log_offset table
└── view/adminhtml/                     # Test ping template + JS
```

## Payload schema

Envelope (always present):

```json
{
  "agent_version": "1.0.15",
  "collected_at": "2026-07-03T10:05:00+00:00",
  "collector_errors": ["optional: code: message"]
}
```

### Phase 1 MVP sections

| Key | Collector | Shape |
|-----|-----------|-------|
| `magento` | System | `{version, edition, mode, maintenance, php, store_base_urls[]}` |
| `indexers` | Indexer | `[{id, status, mode, updated_at, backlog?}]` |
| `cron` | Cron | `{stuck[], missed_last_hour[], errors_last_hour[], schedule_rows, last_success_at}` |
| `queues` | Queue | `[{name, new, in_progress}]` — DB queues only; `[]` if RabbitMQ-only |
| `orders_hourly` | Order stats | `[{hour, count, revenue, currency}]` — 168 hourly buckets |
| `orders` | Order stats | `{pending_payment_stuck}` |
| `logs` | Log | `{system_new_lines, exception_new_lines, payment_new_lines, *_log_bytes, recent_exceptions[], recent_payment_errors[]}` |
| `system` | System | `{disk_free_bytes, disk_free_percent, inode_*, disabled_caches[]}` |

### Post-MVP sections (also shipped)

`security`, `composer` / `modules`, `storefront_probe`, `reports`, `infrastructure`, `database` — validated by the SaaS ingest endpoint.

## Admin configuration

**Stores → Configuration → MageWatch → Agent**

| Field | Path | Notes |
|-------|------|-------|
| Enabled | `magewatch/agent/enabled` | Master switch |
| API endpoint URL | `magewatch/agent/endpoint_url` | HTTPS ingest URL |
| Site token | `magewatch/agent/site_token` | Encrypted; Bearer header |
| Stuck cron threshold | `magewatch/agent/stuck_cron_threshold_minutes` | Default 30 |
| Per-collector toggles | `magewatch/collectors/*` | Each collector can be disabled |
| Send test ping | Admin button | AJAX → builds live payload, shows HTTP status |

## Engineering rules

- `strict_types=1`, constructor DI only, no ObjectManager
- All SQL via `ResourceConnection` read connection; aggregated queries, no entity collections
- Collector failures are isolated — one failure logs a warning and lands in `collector_errors`; the rest still send
- HTTP: Magento Curl client, TLS on, 5s connect / 10s total timeout
- Cron must never throw — failures log to `var/log/magewatch.log`

## Out of scope (v1)

Profit/ads analytics, RabbitMQ API introspection, synthetic checkout tests, auto-remediation, per-store payload splitting.
