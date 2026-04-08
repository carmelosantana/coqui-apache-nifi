# Coqui Toolkit: Apache NiFi

Apache NiFi data flow management toolkit for [Coqui](https://github.com/coquibot/coqui). Create, configure, monitor, and deploy NiFi pipelines via the REST API. Includes a PHP pipeline DSL for defining flows programmatically.

## Installation

```bash
composer require coquibot/coqui-toolkit-apache-nifi
```

The toolkit is auto-discovered by Coqui — no additional configuration needed.

## Credentials

Set these via the Coqui `credentials` tool or in your workspace `.env`:

| Variable | Description |
|----------|-------------|
| `NIFI_BASE_URL` | NiFi instance URL (e.g. `https://localhost:8443`) |
| `NIFI_USERNAME` | NiFi username for API authentication |
| `NIFI_PASSWORD` | NiFi password for API authentication |

## Tools

| Tool | Description |
|------|-------------|
| `nifi_process_group` | Manage process groups — list, create, update, delete, start/stop, status |
| `nifi_processor` | Manage processors — CRUD, start/stop, configure properties and scheduling |
| `nifi_connection` | Manage connections — CRUD, queue status, empty queues |
| `nifi_flow` | Monitor flow health — system status, search, history, bulletin board, diagnostics |
| `nifi_controller_service` | Manage controller services — CRUD, enable/disable |
| `nifi_parameter_context` | Manage parameter contexts — CRUD, add/remove parameters |
| `nifi_pipeline` | Deploy complete pipelines from JSON definitions |
| `nifi_provenance` | Query data provenance — search events, trace lineage |

## Pipeline DSL

Define pipelines in PHP using the standalone [php-nifi-pipelines](../php-nifi-pipelines) library:

```php
use CoquiBot\NiFiPipelines\Pipeline;

$pipeline = Pipeline::create('CSV Ingestion')
    ->addProcessor('fetch', 'org.apache.nifi.processors.standard.GetSFTP', [
        'Hostname' => '#{sftp_host}',
        'Remote Path' => '/data/incoming',
    ])
    ->addProcessor('parse', 'org.apache.nifi.processors.standard.ConvertRecord')
    ->addProcessor('store', 'org.apache.nifi.processors.standard.PutDatabaseRecord', [
        'Table Name' => 'inventory',
    ])
    ->connect('fetch', 'parse', ['success'])
    ->connect('parse', 'store', ['success'])
    ->build();
```

Or deploy via JSON through the `nifi_pipeline` tool:

```json
{
    "name": "CSV Ingestion",
    "processors": [
        {"name": "fetch", "type": "org.apache.nifi.processors.standard.GetSFTP", "properties": {"Hostname": "ftp.example.com"}},
        {"name": "parse", "type": "org.apache.nifi.processors.standard.ConvertRecord"},
        {"name": "store", "type": "org.apache.nifi.processors.standard.PutDatabaseRecord"}
    ],
    "connections": [
        {"source": "fetch", "destination": "parse", "relationships": ["success"]},
        {"source": "parse", "destination": "store", "relationships": ["success"]}
    ]
}
```

## Gated Operations

The following operations require user confirmation (or `--auto-approve`):

- `nifi_process_group`: delete, stop
- `nifi_processor`: delete, stop
- `nifi_connection`: delete, empty_queue
- `nifi_controller_service`: delete, disable
- `nifi_parameter_context`: delete
- `nifi_pipeline`: deploy

## Requirements

- PHP 8.4+
- Apache NiFi 2.x instance with REST API access
- Coqui with php-agents ^0.7

## License

MIT
