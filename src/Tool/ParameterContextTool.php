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
 * Manage NiFi Parameter Contexts — named sets of key-value parameters
 * that can be referenced by processors and controller services using #{paramName} syntax.
 */
final readonly class ParameterContextTool
{
    public function __construct(
        private NiFiClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'nifi_parameter_context',
            description: 'Manage NiFi parameter contexts — list all, get details, create, update (add/modify/remove parameters), or delete. Parameters are referenced in processor properties using #{paramName} syntax.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['list', 'get', 'create', 'update', 'delete'],
                    required: true,
                ),
                new StringParameter(
                    'contextId',
                    'Parameter context ID (required for get, update, delete).',
                    required: false,
                ),
                new StringParameter(
                    'name',
                    'Context name (required for create).',
                    required: false,
                ),
                new StringParameter(
                    'description',
                    'Context description (optional for create/update).',
                    required: false,
                ),
                new StringParameter(
                    'parameters',
                    'JSON object of parameters to set (for create/update). Format: {"param_name": "value"} or {"param_name": {"value": "v", "sensitive": true}}.',
                    required: false,
                ),
                new StringParameter(
                    'removeParameters',
                    'Comma-separated parameter names to remove (optional for update).',
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
            'list' => $this->listContexts(),
            'get' => $this->getContext($args),
            'create' => $this->createContext($args),
            'update' => $this->updateContext($args),
            'delete' => $this->deleteContext($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function listContexts(): ToolResult
    {
        return $this->client->get('flow/parameter-contexts')
            ->toToolResultWith('Parameter contexts:');
    }

    /** @param array<string, mixed> $args */
    private function getContext(array $args): ToolResult
    {
        $contextId = $this->requireString($args, 'contextId');
        if ($contextId === null) {
            return ToolResult::error('contextId is required for the "get" action.');
        }

        return $this->client->get("parameter-contexts/{$contextId}", ['includeInheritedParameters' => 'true'])
            ->toToolResultWith('Parameter context details:');
    }

    /** @param array<string, mixed> $args */
    private function createContext(array $args): ToolResult
    {
        $name = $this->requireString($args, 'name');
        if ($name === null) {
            return ToolResult::error('name is required for the "create" action.');
        }

        $component = [
            'name' => $name,
            'parameters' => $this->buildParameterEntries($args),
        ];

        $description = $this->optionalString($args, 'description');
        if ($description !== null) {
            $component['description'] = $description;
        }

        return $this->client->post('parameter-contexts', [
            'revision' => ['version' => 0],
            'component' => $component,
        ])->toToolResultWith("Parameter context '{$name}' created.");
    }

    /** @param array<string, mixed> $args */
    private function updateContext(array $args): ToolResult
    {
        $contextId = $this->requireString($args, 'contextId');
        if ($contextId === null) {
            return ToolResult::error('contextId is required for the "update" action.');
        }

        // Fetch current revision
        $current = $this->client->get("parameter-contexts/{$contextId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $revision = $current->data['revision'] ?? ['version' => 0];

        // Build parameter entries (add/update)
        $parameters = $this->buildParameterEntries($args);

        // Handle removals
        $removeStr = $this->optionalString($args, 'removeParameters');
        if ($removeStr !== null) {
            $removeNames = array_map('trim', explode(',', $removeStr));
            foreach ($removeNames as $removeName) {
                if ($removeName !== '') {
                    $parameters[] = [
                        'parameter' => [
                            'name' => $removeName,
                        ],
                    ];
                }
            }
        }

        $component = [
            'id' => $contextId,
            'parameters' => $parameters,
        ];

        $description = $this->optionalString($args, 'description');
        if ($description !== null) {
            $component['description'] = $description;
        }

        // NiFi parameter context updates are async — submit as update request
        $result = $this->client->post("parameter-contexts/{$contextId}/update-requests", [
            'revision' => $revision,
            'id' => $contextId,
            'component' => $component,
        ]);

        if (!$result->success) {
            return $result->toToolResult();
        }

        // Poll for completion if we got a request ID
        if (is_array($result->data)) {
            $requestId = $result->data['request']['requestId']
                ?? $result->data['parameterContextUpdate']['request']['requestId']
                ?? null;

            if ($requestId !== null) {
                // Single poll attempt — most updates complete quickly
                $status = $this->client->get("parameter-contexts/{$contextId}/update-requests/{$requestId}");
                // Clean up
                $this->client->delete("parameter-contexts/{$contextId}/update-requests/{$requestId}");
                return $status->toToolResultWith('Parameter context update:');
            }
        }

        return $result->toToolResultWith('Parameter context update submitted.');
    }

    /** @param array<string, mixed> $args */
    private function deleteContext(array $args): ToolResult
    {
        $contextId = $this->requireString($args, 'contextId');
        if ($contextId === null) {
            return ToolResult::error('contextId is required for the "delete" action.');
        }

        $current = $this->client->get("parameter-contexts/{$contextId}");
        if (!$current->success || !is_array($current->data)) {
            return $current->toToolResult();
        }

        $version = $current->data['revision']['version'] ?? 0;

        return $this->client->delete("parameter-contexts/{$contextId}", [
            'version' => (string) $version,
        ])->toToolResultWith('Parameter context deleted.');
    }

    /**
     * Build NiFi parameter entry objects from the parameters JSON argument.
     *
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    private function buildParameterEntries(array $args): array
    {
        $raw = $this->optionalString($args, 'parameters');
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $name => $value) {
            if (is_array($value)) {
                // Object format: {"value": "...", "sensitive": true, "description": "..."}
                $entry = [
                    'parameter' => [
                        'name' => (string) $name,
                        'value' => (string) ($value['value'] ?? ''),
                        'sensitive' => (bool) ($value['sensitive'] ?? false),
                    ],
                ];
                if (isset($value['description'])) {
                    $entry['parameter']['description'] = (string) $value['description'];
                }
            } else {
                // Simple string format
                $entry = [
                    'parameter' => [
                        'name' => (string) $name,
                        'value' => (string) $value,
                        'sensitive' => false,
                    ],
                ];
            }
            $entries[] = $entry;
        }

        return $entries;
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
