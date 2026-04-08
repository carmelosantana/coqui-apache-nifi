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
 * Manage individual NiFi processors — the building blocks of data flows.
 */
final readonly class ProcessorTool
{
    public function __construct(
        private NiFiClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'nifi_processor',
            description: 'Manage NiFi processors — list in a group, get details, create, update properties/scheduling, delete, start, stop, or check status.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['list', 'get', 'create', 'update', 'delete', 'start', 'stop', 'status'],
                    required: true,
                ),
                new StringParameter(
                    'processorId',
                    'Processor ID (required for get, update, delete, start, stop, status).',
                    required: false,
                ),
                new StringParameter(
                    'groupId',
                    'Process group ID (required for list and create).',
                    required: false,
                ),
                new StringParameter(
                    'type',
                    'Fully-qualified processor type (required for create). e.g. "org.apache.nifi.processors.standard.GetFile".',
                    required: false,
                ),
                new StringParameter(
                    'name',
                    'Processor name (required for create, optional for update).',
                    required: false,
                ),
                new StringParameter(
                    'properties',
                    'JSON object of processor properties (optional for create/update). e.g. {"Input Directory": "/data/in"}.',
                    required: false,
                ),
                new StringParameter(
                    'schedulingStrategy',
                    'Scheduling strategy: TIMER_DRIVEN, CRON_DRIVEN, or EVENT_DRIVEN (optional for create/update).',
                    required: false,
                ),
                new StringParameter(
                    'schedulingPeriod',
                    'Scheduling period, e.g. "0 sec", "5 min", "0 0/5 * * * ?" for cron (optional for create/update).',
                    required: false,
                ),
                new StringParameter(
                    'concurrentTasks',
                    'Number of concurrent tasks (optional for create/update).',
                    required: false,
                ),
                new StringParameter(
                    'autoTerminatedRelationships',
                    'Comma-separated list of relationships to auto-terminate (optional for create/update).',
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
            'list' => $this->listProcessors($args),
            'get' => $this->getProcessor($args),
            'create' => $this->createProcessor($args),
            'update' => $this->updateProcessor($args),
            'delete' => $this->deleteProcessor($args),
            'start' => $this->changeRunStatus($args, 'RUNNING'),
            'stop' => $this->changeRunStatus($args, 'STOPPED'),
            'status' => $this->getStatus($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /** @param array<string, mixed> $args */
    private function listProcessors(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for the "list" action.');
        }

        return $this->client->get("process-groups/{$groupId}/processors")
            ->toToolResultWith("Processors in group '{$groupId}':");
    }

    /** @param array<string, mixed> $args */
    private function getProcessor(array $args): ToolResult
    {
        $processorId = $this->requireString($args, 'processorId');
        if ($processorId === null) {
            return ToolResult::error('processorId is required for the "get" action.');
        }

        return $this->client->get("processors/{$processorId}")
            ->toToolResultWith('Processor details:');
    }

    /** @param array<string, mixed> $args */
    private function createProcessor(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for the "create" action.');
        }

        $type = $this->requireString($args, 'type');
        if ($type === null) {
            return ToolResult::error('type is required for the "create" action.');
        }

        $name = $this->requireString($args, 'name') ?? $this->shortClassName($type);

        $component = [
            'type' => $type,
            'name' => $name,
            'position' => ['x' => 0.0, 'y' => 0.0],
            'config' => [],
        ];

        $properties = $this->parseJsonObject($args, 'properties');
        if ($properties !== null) {
            $component['config']['properties'] = $properties;
        }

        $this->applySchedulingConfig($args, $component);
        $this->applyAutoTerminated($args, $component);

        return $this->client->post("process-groups/{$groupId}/processors", [
            'revision' => ['version' => 0],
            'component' => $component,
        ])->toToolResultWith("Processor '{$name}' created.");
    }

    /** @param array<string, mixed> $args */
    private function updateProcessor(array $args): ToolResult
    {
        $processorId = $this->requireString($args, 'processorId');
        if ($processorId === null) {
            return ToolResult::error('processorId is required for the "update" action.');
        }

        // Fetch current state for revision
        $current = $this->client->get("processors/{$processorId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $revision = $current->data['revision'] ?? ['version' => 0];
        $component = ['id' => $processorId, 'config' => []];

        $name = $this->optionalString($args, 'name');
        if ($name !== null) {
            $component['name'] = $name;
        }

        $properties = $this->parseJsonObject($args, 'properties');
        if ($properties !== null) {
            $component['config']['properties'] = $properties;
        }

        $this->applySchedulingConfig($args, $component);
        $this->applyAutoTerminated($args, $component);

        if ($component['config'] === []) {
            unset($component['config']);
        }

        return $this->client->put("processors/{$processorId}", [
            'revision' => $revision,
            'component' => $component,
        ])->toToolResultWith('Processor updated.');
    }

    /** @param array<string, mixed> $args */
    private function deleteProcessor(array $args): ToolResult
    {
        $processorId = $this->requireString($args, 'processorId');
        if ($processorId === null) {
            return ToolResult::error('processorId is required for the "delete" action.');
        }

        $current = $this->client->get("processors/{$processorId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $version = $current->data['revision']['version'] ?? 0;

        return $this->client->delete("processors/{$processorId}", [
            'version' => (string) $version,
        ])->toToolResultWith('Processor deleted.');
    }

    /** @param array<string, mixed> $args */
    private function changeRunStatus(array $args, string $state): ToolResult
    {
        $processorId = $this->requireString($args, 'processorId');
        if ($processorId === null) {
            return ToolResult::error('processorId is required for start/stop actions.');
        }

        // Fetch current revision for the run-status endpoint
        $current = $this->client->get("processors/{$processorId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $revision = $current->data['revision'] ?? ['version' => 0];

        return $this->client->put("processors/{$processorId}/run-status", [
            'revision' => $revision,
            'state' => $state,
        ])->toToolResultWith("Processor set to {$state}.");
    }

    /** @param array<string, mixed> $args */
    private function getStatus(array $args): ToolResult
    {
        $processorId = $this->requireString($args, 'processorId');
        if ($processorId === null) {
            return ToolResult::error('processorId is required for the "status" action.');
        }

        return $this->client->get("processors/{$processorId}")
            ->toToolResultWith('Processor status:');
    }

    /**
     * Apply scheduling config fields to a component array.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> &$component
     */
    private function applySchedulingConfig(array $args, array &$component): void
    {
        $strategy = $this->optionalString($args, 'schedulingStrategy');
        if ($strategy !== null) {
            $component['config']['schedulingStrategy'] = $strategy;
        }

        $period = $this->optionalString($args, 'schedulingPeriod');
        if ($period !== null) {
            $component['config']['schedulingPeriod'] = $period;
        }

        $tasks = $this->optionalString($args, 'concurrentTasks');
        if ($tasks !== null) {
            $component['config']['concurrentlySchedulableTaskCount'] = $tasks;
        }
    }

    /**
     * Apply auto-terminated relationships to a component array.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> &$component
     */
    private function applyAutoTerminated(array $args, array &$component): void
    {
        $autoTerm = $this->optionalString($args, 'autoTerminatedRelationships');
        if ($autoTerm !== null) {
            $component['config']['autoTerminatedRelationships'] = array_map(
                'trim',
                explode(',', $autoTerm),
            );
        }
    }

    /**
     * Parse a JSON string argument into an associative array.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function parseJsonObject(array $args, string $key): ?array
    {
        $raw = $this->optionalString($args, $key);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Extract the short class name from a fully-qualified NiFi processor type.
     */
    private function shortClassName(string $fqcn): string
    {
        $parts = explode('.', $fqcn);
        return end($parts) ?: $fqcn;
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
