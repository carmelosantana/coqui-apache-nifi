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
 * Manage NiFi Controller Services — shared services like DB connection pools and SSL contexts.
 */
final readonly class ControllerServiceTool
{
    public function __construct(
        private NiFiClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'nifi_controller_service',
            description: 'Manage NiFi controller services (DB pools, SSL contexts, record readers/writers) — list, get details, create, update, delete, enable, or disable.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['list', 'get', 'create', 'update', 'delete', 'enable', 'disable'],
                    required: true,
                ),
                new StringParameter(
                    'serviceId',
                    'Controller service ID (required for get, update, delete, enable, disable).',
                    required: false,
                ),
                new StringParameter(
                    'groupId',
                    'Process group ID (required for list and create).',
                    required: false,
                ),
                new StringParameter(
                    'type',
                    'Fully-qualified service type (required for create). e.g. "org.apache.nifi.dbcp.DBCPConnectionPool".',
                    required: false,
                ),
                new StringParameter(
                    'name',
                    'Service name (required for create, optional for update).',
                    required: false,
                ),
                new StringParameter(
                    'properties',
                    'JSON object of service properties (optional for create/update). e.g. {"Database Connection URL": "jdbc:postgresql://localhost/mydb"}.',
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
            'list' => $this->listServices($args),
            'get' => $this->getService($args),
            'create' => $this->createService($args),
            'update' => $this->updateService($args),
            'delete' => $this->deleteService($args),
            'enable' => $this->changeState($args, 'ENABLED'),
            'disable' => $this->changeState($args, 'DISABLED'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /** @param array<string, mixed> $args */
    private function listServices(array $args): ToolResult
    {
        $groupId = $this->requireString($args, 'groupId');
        if ($groupId === null) {
            return ToolResult::error('groupId is required for the "list" action.');
        }

        return $this->client->get("flow/process-groups/{$groupId}/controller-services")
            ->toToolResultWith("Controller services in group '{$groupId}':");
    }

    /** @param array<string, mixed> $args */
    private function getService(array $args): ToolResult
    {
        $serviceId = $this->requireString($args, 'serviceId');
        if ($serviceId === null) {
            return ToolResult::error('serviceId is required for the "get" action.');
        }

        return $this->client->get("controller-services/{$serviceId}")
            ->toToolResultWith('Controller service details:');
    }

    /** @param array<string, mixed> $args */
    private function createService(array $args): ToolResult
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
        ];

        $properties = $this->parseJsonObject($args, 'properties');
        if ($properties !== null) {
            $component['properties'] = $properties;
        }

        return $this->client->post("process-groups/{$groupId}/controller-services", [
            'revision' => ['version' => 0],
            'component' => $component,
        ])->toToolResultWith("Controller service '{$name}' created.");
    }

    /** @param array<string, mixed> $args */
    private function updateService(array $args): ToolResult
    {
        $serviceId = $this->requireString($args, 'serviceId');
        if ($serviceId === null) {
            return ToolResult::error('serviceId is required for the "update" action.');
        }

        $current = $this->client->get("controller-services/{$serviceId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $revision = $current->data['revision'] ?? ['version' => 0];
        $component = ['id' => $serviceId];

        $name = $this->optionalString($args, 'name');
        if ($name !== null) {
            $component['name'] = $name;
        }

        $properties = $this->parseJsonObject($args, 'properties');
        if ($properties !== null) {
            $component['properties'] = $properties;
        }

        return $this->client->put("controller-services/{$serviceId}", [
            'revision' => $revision,
            'component' => $component,
        ])->toToolResultWith('Controller service updated.');
    }

    /** @param array<string, mixed> $args */
    private function deleteService(array $args): ToolResult
    {
        $serviceId = $this->requireString($args, 'serviceId');
        if ($serviceId === null) {
            return ToolResult::error('serviceId is required for the "delete" action.');
        }

        $current = $this->client->get("controller-services/{$serviceId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $version = $current->data['revision']['version'] ?? 0;

        return $this->client->delete("controller-services/{$serviceId}", [
            'version' => (string) $version,
        ])->toToolResultWith('Controller service deleted.');
    }

    /** @param array<string, mixed> $args */
    private function changeState(array $args, string $state): ToolResult
    {
        $serviceId = $this->requireString($args, 'serviceId');
        if ($serviceId === null) {
            return ToolResult::error('serviceId is required for enable/disable actions.');
        }

        $current = $this->client->get("controller-services/{$serviceId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $revision = $current->data['revision'] ?? ['version' => 0];

        return $this->client->put("controller-services/{$serviceId}/run-status", [
            'revision' => $revision,
            'state' => $state,
        ])->toToolResultWith("Controller service set to {$state}.");
    }

    /**
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
