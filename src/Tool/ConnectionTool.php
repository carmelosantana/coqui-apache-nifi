<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitApacheNifi\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitApacheNifi\Runtime\NiFiClient;

/**
 * Manage NiFi connections between processors, ports, and funnels.
 */
final readonly class ConnectionTool
{
    public function __construct(
        private NiFiClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'nifi_connection',
            description: 'Manage NiFi connections — list in a group, get details, create, update, delete, check queue status, or empty a queue.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['list', 'get', 'create', 'update', 'delete', 'queue_status', 'empty_queue'],
                    required: true,
                ),
                new StringParameter(
                    'connectionId',
                    'Connection ID (required for get, update, delete, queue_status, empty_queue).',
                    required: false,
                ),
                new StringParameter(
                    'groupId',
                    'Process group ID (required for list and create).',
                    required: false,
                ),
                new StringParameter(
                    'sourceId',
                    'Source component ID (required for create).',
                    required: false,
                ),
                new StringParameter(
                    'sourceType',
                    'Source type: PROCESSOR, INPUT_PORT, FUNNEL, or REMOTE_OUTPUT_PORT (default: PROCESSOR).',
                    required: false,
                ),
                new StringParameter(
                    'destinationId',
                    'Destination component ID (required for create).',
                    required: false,
                ),
                new StringParameter(
                    'destinationType',
                    'Destination type: PROCESSOR, OUTPUT_PORT, FUNNEL, or REMOTE_INPUT_PORT (default: PROCESSOR).',
                    required: false,
                ),
                new StringParameter(
                    'relationships',
                    'Comma-separated list of relationships to route (required for create). e.g. "success,failure".',
                    required: false,
                ),
                new StringParameter(
                    'backPressureObjectThreshold',
                    'Back pressure object count threshold (optional for create/update).',
                    required: false,
                ),
                new StringParameter(
                    'backPressureDataSizeThreshold',
                    'Back pressure data size threshold, e.g. "1 GB" (optional for create/update).',
                    required: false,
                ),
            ],
            callback: fn(array $args) => $this->execute($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function execute(array $args): ToolResult
    {
        $action = trim((string) ($args['action'] ?? ''));

        return match ($action) {
            'list' => $this->listConnections($args),
            'get' => $this->getConnection($args),
            'create' => $this->createConnection($args),
            'update' => $this->updateConnection($args),
            'delete' => $this->deleteConnection($args),
            'queue_status' => $this->queueStatus($args),
            'empty_queue' => $this->emptyQueue($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /** @param array<string, mixed> $args */
    private function listConnections(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for the "list" action.');
        }

        return $this->client->get("process-groups/{$groupId}/connections")
            ->toToolResultWith("Connections in group '{$groupId}':");
    }

    /** @param array<string, mixed> $args */
    private function getConnection(array $args): ToolResult
    {
        $connectionId = $this->requireString($args, 'connectionId');
        if ($connectionId === null) {
            return ToolResult::error('connectionId is required for the "get" action.');
        }

        return $this->client->get("connections/{$connectionId}")
            ->toToolResultWith('Connection details:');
    }

    /** @param array<string, mixed> $args */
    private function createConnection(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for the "create" action.');
        }

        $sourceId = $this->requireString($args, 'sourceId');
        $destinationId = $this->requireString($args, 'destinationId');
        if ($sourceId === null || $destinationId === null) {
            return ToolResult::error('sourceId and destinationId are required for the "create" action.');
        }

        $relationships = $this->requireString($args, 'relationships');
        if ($relationships === null) {
            return ToolResult::error('relationships is required for the "create" action (comma-separated list).');
        }

        $sourceType = $this->optionalString($args, 'sourceType') ?? 'PROCESSOR';
        $destinationType = $this->optionalString($args, 'destinationType') ?? 'PROCESSOR';

        $component = [
            'source' => [
                'id' => $sourceId,
                'type' => $sourceType,
            ],
            'destination' => [
                'id' => $destinationId,
                'type' => $destinationType,
            ],
            'selectedRelationships' => array_map('trim', explode(',', $relationships)),
        ];

        $bpObjects = $this->optionalString($args, 'backPressureObjectThreshold');
        if ($bpObjects !== null) {
            $component['backPressureObjectThreshold'] = $bpObjects;
        }

        $bpSize = $this->optionalString($args, 'backPressureDataSizeThreshold');
        if ($bpSize !== null) {
            $component['backPressureDataSizeThreshold'] = $bpSize;
        }

        return $this->client->post("process-groups/{$groupId}/connections", [
            'revision' => ['version' => 0],
            'component' => $component,
        ])->toToolResultWith('Connection created.');
    }

    /** @param array<string, mixed> $args */
    private function updateConnection(array $args): ToolResult
    {
        $connectionId = $this->requireString($args, 'connectionId');
        if ($connectionId === null) {
            return ToolResult::error('connectionId is required for the "update" action.');
        }

        $current = $this->client->get("connections/{$connectionId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $revision = $current->data['revision'] ?? ['version' => 0];
        $component = ['id' => $connectionId];

        $relationships = $this->optionalString($args, 'relationships');
        if ($relationships !== null) {
            $component['selectedRelationships'] = array_map('trim', explode(',', $relationships));
        }

        $bpObjects = $this->optionalString($args, 'backPressureObjectThreshold');
        if ($bpObjects !== null) {
            $component['backPressureObjectThreshold'] = $bpObjects;
        }

        $bpSize = $this->optionalString($args, 'backPressureDataSizeThreshold');
        if ($bpSize !== null) {
            $component['backPressureDataSizeThreshold'] = $bpSize;
        }

        return $this->client->put("connections/{$connectionId}", [
            'revision' => $revision,
            'component' => $component,
        ])->toToolResultWith('Connection updated.');
    }

    /** @param array<string, mixed> $args */
    private function deleteConnection(array $args): ToolResult
    {
        $connectionId = $this->requireString($args, 'connectionId');
        if ($connectionId === null) {
            return ToolResult::error('connectionId is required for the "delete" action.');
        }

        $current = $this->client->get("connections/{$connectionId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $version = $current->data['revision']['version'] ?? 0;

        return $this->client->delete("connections/{$connectionId}", [
            'version' => (string) $version,
        ])->toToolResultWith('Connection deleted.');
    }

    /** @param array<string, mixed> $args */
    private function queueStatus(array $args): ToolResult
    {
        $connectionId = $this->requireString($args, 'connectionId');
        if ($connectionId === null) {
            return ToolResult::error('connectionId is required for the "queue_status" action.');
        }

        // Create a listing request to get queue counts
        $result = $this->client->post("flowfile-queues/{$connectionId}/listing-requests");
        if (!$result->success || !is_array($result->data)) {
            return $result->toToolResult();
        }

        $requestId = $result->data['listingRequest']['id'] ?? null;
        if ($requestId === null) {
            return ToolResult::error('Failed to create queue listing request.');
        }

        // Poll for completion (single attempt — NiFi usually returns immediately for small queues)
        $status = $this->client->get("flowfile-queues/{$connectionId}/listing-requests/{$requestId}");

        // Clean up the listing request
        $this->client->delete("flowfile-queues/{$connectionId}/listing-requests/{$requestId}");

        return $status->toToolResultWith('Queue status:');
    }

    /** @param array<string, mixed> $args */
    private function emptyQueue(array $args): ToolResult
    {
        $connectionId = $this->requireString($args, 'connectionId');
        if ($connectionId === null) {
            return ToolResult::error('connectionId is required for the "empty_queue" action.');
        }

        $result = $this->client->post("flowfile-queues/{$connectionId}/drop-requests");
        if (!$result->success || !is_array($result->data)) {
            return $result->toToolResult();
        }

        $requestId = $result->data['dropRequest']['id'] ?? null;
        if ($requestId === null) {
            return ToolResult::error('Failed to create drop request.');
        }

        // Poll for completion
        $status = $this->client->get("flowfile-queues/{$connectionId}/drop-requests/{$requestId}");

        return $status->toToolResultWith('Queue emptied:');
    }

    /** @param array<string, mixed> $args */
    private function requireString(array $args, string $key): ?string
    {
        $value = trim((string) ($args[$key] ?? ''));
        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $args */
    private function optionalString(array $args, string $key): ?string
    {
        if (!isset($args[$key])) {
            return null;
        }
        $value = trim((string) $args[$key]);
        return $value !== '' ? $value : null;
    }
}
