<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitApacheNifi;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitApacheNifi\Runtime\NiFiClient;
use CarmeloSantana\CoquiToolkitApacheNifi\Tool\ConnectionTool;
use CarmeloSantana\CoquiToolkitApacheNifi\Tool\ControllerServiceTool;
use CarmeloSantana\CoquiToolkitApacheNifi\Tool\FlowTool;
use CarmeloSantana\CoquiToolkitApacheNifi\Tool\ParameterContextTool;
use CarmeloSantana\CoquiToolkitApacheNifi\Tool\PipelineTool;
use CarmeloSantana\CoquiToolkitApacheNifi\Tool\ProcessGroupTool;
use CarmeloSantana\CoquiToolkitApacheNifi\Tool\ProcessorTool;
use CarmeloSantana\CoquiToolkitApacheNifi\Tool\ProvenanceTool;

/**
 * Apache NiFi data flow management toolkit for Coqui.
 *
 * Provides comprehensive NiFi REST API access: process groups, processors,
 * connections, controller services, parameter contexts, provenance,
 * flow monitoring, and PHP pipeline DSL deployment.
 */
final class NiFiToolkit implements ToolkitInterface
{
    private readonly NiFiClient $client;

    public function __construct(
        ?NiFiClient $client = null,
    ) {
        $this->client = $client ?? NiFiClient::fromEnv();
    }

    /**
     * @return array<\CarmeloSantana\PHPAgents\Contract\ToolInterface>
     */
    public function tools(): array
    {
        return [
            (new ProcessGroupTool($this->client))->build(),
            (new ProcessorTool($this->client))->build(),
            (new ConnectionTool($this->client))->build(),
            (new FlowTool($this->client))->build(),
            (new ControllerServiceTool($this->client))->build(),
            (new ParameterContextTool($this->client))->build(),
            (new PipelineTool($this->client))->build(),
            (new ProvenanceTool($this->client))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        <NIFI-GUIDELINES>
        ## Apache NiFi Data Flow Toolkit

        You have full access to the Apache NiFi 2.x REST API through the following tools:

        ### Tool Overview
        | Tool | Purpose |
        |------|---------|
        | **nifi_process_group** | List/get/create/update/delete/start/stop process groups and check their status |
        | **nifi_processor** | Manage individual processors — CRUD, start/stop, configure properties and scheduling |
        | **nifi_connection** | Manage connections between components — CRUD, check queue status, empty queues |
        | **nifi_flow** | Monitor overall flow health — system status, search components, view history, bulletin board, diagnostics |
        | **nifi_controller_service** | Manage shared services (DB pools, SSL contexts, record readers/writers) — CRUD, enable/disable |
        | **nifi_parameter_context** | Manage parameter contexts — CRUD, add/remove parameters. Referenced via #{paramName} in properties |
        | **nifi_pipeline** | Deploy complete pipelines from JSON definitions — validate, preview API payloads, or deploy to NiFi |
        | **nifi_provenance** | Query data provenance — search events, get event details, trace data lineage |

        ### NiFi Concepts

        **Process Group** — Container that organizes processors and connections into a logical unit. The root canvas is identified by "root".

        **Processor** — The building block of data flows. Each processor performs a specific operation (read files, transform data, write to database, etc.). Processors are identified by their fully-qualified Java class name (e.g., `org.apache.nifi.processors.standard.GetFile`).

        **Connection** — Links two components and routes FlowFiles between them based on relationships (e.g., "success", "failure", "retry").

        **FlowFile** — The data object that moves through NiFi. Each FlowFile has content (the data) and attributes (metadata key-value pairs).

        **Controller Service** — A shared service available to processors within a process group (e.g., database connection pool, SSL context, record reader/writer).

        **Parameter Context** — A named collection of parameters that can be referenced in processor and service properties using `#{paramName}` syntax. Supports sensitive values.

        **Provenance** — Complete audit trail of every data transformation. Tracks where data came from, what happened to it, and where it went.

        ### Common Workflows

        **Build a pipeline from scratch:**
        1. `nifi_process_group(action: "create", parentGroupId: "root", name: "My Pipeline")` — create a container
        2. `nifi_processor(action: "create", groupId: "<groupId>", type: "org.apache.nifi.processors.standard.GetSFTP", name: "Fetch Files", properties: '{"Hostname": "ftp.example.com", "Remote Path": "/data"}')` — add processors
        3. `nifi_processor(action: "create", groupId: "<groupId>", type: "org.apache.nifi.processors.standard.ConvertRecord", name: "CSV to JSON")` — add more processors
        4. `nifi_connection(action: "create", groupId: "<groupId>", sourceId: "<fetchId>", destinationId: "<convertId>", relationships: "success")` — connect them
        5. `nifi_process_group(action: "start", groupId: "<groupId>")` — start the entire group

        **Deploy a complete pipeline from JSON:**
        1. `nifi_pipeline(action: "validate", definition: '{"name": "...", "processors": [...], "connections": [...]}')` — check first
        2. `nifi_pipeline(action: "deploy", definition: '...', parentGroupId: "root", startAfterDeploy: "true")` — deploy and start

        **Monitor flow health:**
        1. `nifi_flow(action: "status")` — check overall system health
        2. `nifi_flow(action: "bulletin_board")` — check for warnings/errors
        3. `nifi_flow(action: "search", query: "GetSFTP")` — find specific components
        4. `nifi_process_group(action: "status", groupId: "<groupId>")` — drill into a specific group

        **Debug data flow issues:**
        1. `nifi_connection(action: "queue_status", connectionId: "<connId>")` — check if data is stuck
        2. `nifi_provenance(action: "search", processorId: "<processorId>")` — see what happened to data
        3. `nifi_provenance(action: "get_event", eventId: "<eventId>")` — get details of a specific event
        4. `nifi_provenance(action: "lineage", eventId: "<eventId>")` — trace complete data path

        **Configure parameterized flows:**
        1. `nifi_parameter_context(action: "create", name: "prod-config", parameters: '{"db_host": "db.example.com", "db_password": {"value": "secret", "sensitive": true}}')` — create context
        2. Reference in processor properties using `#{db_host}` syntax

        ### Pipeline JSON Definition Schema

        The `nifi_pipeline` tool accepts pipeline definitions in this JSON format:

        ```json
        {
            "name": "My Pipeline",
            "processors": [
                {
                    "name": "Fetch Files",
                    "type": "org.apache.nifi.processors.standard.GetSFTP",
                    "properties": {
                        "Hostname": "#{sftp_host}",
                        "Port": "22",
                        "Remote Path": "/data/incoming"
                    },
                    "scheduling": {
                        "schedulingStrategy": "TIMER_DRIVEN",
                        "schedulingPeriod": "1 min"
                    },
                    "autoTerminatedRelationships": ["failure"]
                }
            ],
            "connections": [
                {
                    "source": "Fetch Files",
                    "destination": "Parse CSV",
                    "relationships": ["success"]
                }
            ],
            "controllerServices": [
                {
                    "name": "DB Connection Pool",
                    "type": "org.apache.nifi.dbcp.DBCPConnectionPool",
                    "properties": {
                        "Database Connection URL": "#{db_url}",
                        "Database Driver Class Name": "org.postgresql.Driver"
                    }
                }
            ],
            "parameterContexts": [
                {
                    "name": "pipeline-config",
                    "description": "Pipeline configuration parameters",
                    "parameters": {
                        "sftp_host": "ftp.example.com",
                        "db_url": "jdbc:postgresql://localhost/mydb",
                        "db_password": {"value": "secret", "sensitive": true}
                    }
                }
            ]
        }
        ```

        ### Common NiFi Processor Types

        **Data Ingestion:**
        - `org.apache.nifi.processors.standard.GetFile` — read files from local filesystem
        - `org.apache.nifi.processors.standard.GetSFTP` — fetch files via SFTP
        - `org.apache.nifi.processors.standard.ListenHTTP` — receive HTTP requests
        - `org.apache.nifi.processors.standard.ConsumeKafka` — consume from Kafka topics
        - `org.apache.nifi.processors.standard.GenerateFlowFile` — generate test data
        - `org.apache.nifi.processors.standard.ExecuteSQL` — query databases

        **Data Transformation:**
        - `org.apache.nifi.processors.standard.ConvertRecord` — convert between formats (CSV, JSON, Avro, etc.)
        - `org.apache.nifi.processors.standard.UpdateRecord` — modify record fields
        - `org.apache.nifi.processors.standard.JoltTransformJSON` — JOLT JSON transformation
        - `org.apache.nifi.processors.standard.ReplaceText` — regex text replacement
        - `org.apache.nifi.processors.standard.SplitRecord` — split records into batches
        - `org.apache.nifi.processors.standard.QueryRecord` — SQL queries on FlowFile content

        **Data Output:**
        - `org.apache.nifi.processors.standard.PutFile` — write to local filesystem
        - `org.apache.nifi.processors.standard.PutSFTP` — upload via SFTP
        - `org.apache.nifi.processors.standard.PutSQL` — execute SQL INSERT/UPDATE
        - `org.apache.nifi.processors.standard.PutDatabaseRecord` — batch database writes
        - `org.apache.nifi.processors.standard.PublishKafka` — publish to Kafka
        - `org.apache.nifi.processors.standard.InvokeHTTP` — make HTTP requests

        **Routing & Control:**
        - `org.apache.nifi.processors.standard.RouteOnAttribute` — route by FlowFile attributes
        - `org.apache.nifi.processors.standard.RouteOnContent` — route by content matching
        - `org.apache.nifi.processors.standard.UpdateAttribute` — set FlowFile attributes
        - `org.apache.nifi.processors.standard.LogAttribute` — log attributes for debugging
        - `org.apache.nifi.processors.standard.Wait` / `Notify` — synchronization

        ### Important Notes

        - **Process group IDs** are UUIDs. Use "root" to reference the root canvas.
        - **Revision control** — NiFi uses optimistic locking. The toolkit handles revision tracking automatically for update/delete operations.
        - **Processor types** must be fully-qualified Java class names.
        - **Controller services** must be enabled before processors can reference them.
        - **Parameter contexts** must be bound to a process group before processors in that group can reference parameters.
        - **Sensitive parameters** (passwords, API keys) are write-only — their values are never returned by the API.
        - **Starting/stopping** a process group affects all processors within it recursively.
        - **Provenance queries** are asynchronous — the toolkit handles polling automatically.
        </NIFI-GUIDELINES>
        GUIDELINES;
    }
}
