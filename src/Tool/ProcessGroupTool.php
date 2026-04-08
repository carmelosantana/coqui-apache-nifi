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
 * Manage NiFi Process Groups — the organizational containers for data flows.
 */
final readonly class ProcessGroupTool
{
    public function __construct(
        private NiFiClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'nifi_process_group',
            description: 'Manage NiFi process groups — list, get details, create, update, delete, start/stop all processors within a group, or check status.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['list', 'get', 'create', 'update', 'delete', 'start', 'stop', 'status'],
                    required: true,
                ),
                new StringParameter(
                    'groupId',
                    'Process group ID. Use "root" for the root canvas. Required for list (parent), get, update, delete, start, stop, status.',
                    required: false,
                ),
                new StringParameter(
                    'parentGroupId',
                    'Parent process group ID (required for create). Use "root" for top-level.',
                    required: false,
                ),
                new StringParameter(
                    'name',
                    'Process group name (required for create, optional for update).',
                    required: false,
                ),
                new StringParameter(
                    'parameterContextId',
                    'Parameter context ID to bind to this group (optional for create/update).',
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
            'list' => $this->listGroups($args),
            'get' => $this->getGroup($args),
            'create' => $this->createGroup($args),
            'update' => $this->updateGroup($args),
            'delete' => $this->deleteGroup($args),
            'start' => $this->changeState($args, 'RUNNING'),
            'stop' => $this->changeState($args, 'STOPPED'),
            'status' => $this->getStatus($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /** @param array<string, mixed> $args */
    private function listGroups(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId') ?? 'root';

        return $this->client->get("flow/process-groups/{$groupId}")
            ->toToolResultWith("Process groups in '{$groupId}':");
    }

    /** @param array<string, mixed> $args */
    private function getGroup(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for the "get" action.');
        }

        return $this->client->get("process-groups/{$groupId}")
            ->toToolResultWith('Process group details:');
    }

    /** @param array<string, mixed> $args */
    private function createGroup(array $args): ToolResult
    {
        $parentGroupId = $this->requireString($args, 'parentGroupId') ?? 'root';
        $name = $this->requireString($args, 'name');
        if ($name === null) {
            return ToolResult::error('name is required for the "create" action.');
        }

        $body = [
            'revision' => ['version' => 0],
            'component' => [
                'name' => $name,
                'position' => ['x' => 0.0, 'y' => 0.0],
            ],
        ];

        $paramContextId = $this->optionalString($args, 'parameterContextId');
        if ($paramContextId !== null) {
            $body['component']['parameterContext'] = ['id' => $paramContextId];
        }

        return $this->client->post("process-groups/{$parentGroupId}/process-groups", $body)
            ->toToolResultWith("Process group '{$name}' created.");
    }

    /** @param array<string, mixed> $args */
    private function updateGroup(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for the "update" action.');
        }

        // Fetch current revision
        $current = $this->client->get("process-groups/{$groupId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $revision = $current->data['revision'] ?? ['version' => 0];
        $component = ['id' => $groupId];

        $name = $this->optionalString($args, 'name');
        if ($name !== null) {
            $component['name'] = $name;
        }

        $paramContextId = $this->optionalString($args, 'parameterContextId');
        if ($paramContextId !== null) {
            $component['parameterContext'] = ['id' => $paramContextId];
        }

        return $this->client->put("process-groups/{$groupId}", [
            'revision' => $revision,
            'component' => $component,
        ])->toToolResultWith('Process group updated.');
    }

    /** @param array<string, mixed> $args */
    private function deleteGroup(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for the "delete" action.');
        }

        // Fetch current revision for optimistic locking
        $current = $this->client->get("process-groups/{$groupId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $version = $current->data['revision']['version'] ?? 0;

        return $this->client->delete("process-groups/{$groupId}", [
            'version' => (string) $version,
        ])->toToolResultWith('Process group deleted.');
    }

    /** @param array<string, mixed> $args */
    private function changeState(array $args, string $state): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for start/stop actions.');
        }

        return $this->client->put("flow/process-groups/{$groupId}", [
            'id' => $groupId,
            'state' => $state,
        ])->toToolResultWith("Process group '{$groupId}' set to {$state}.");
    }

    /** @param array<string, mixed> $args */
    private function getStatus(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId') ?? 'root';

        return $this->client->get("flow/process-groups/{$groupId}/status")
            ->toToolResultWith("Process group status for '{$groupId}':");
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
