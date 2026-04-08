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
 * High-level NiFi flow monitoring and search operations.
 */
final readonly class FlowTool
{
    public function __construct(
        private NiFiClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'nifi_flow',
            description: 'Monitor NiFi flow health — check overall system status, search for components by name/type, view action history, read bulletin board warnings, or get system diagnostics.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['status', 'search', 'history', 'bulletin_board', 'diagnostics'],
                    required: true,
                ),
                new StringParameter(
                    'query',
                    'Search query string (required for search). Searches processor names, types, properties, etc.',
                    required: false,
                ),
                new StringParameter(
                    'groupId',
                    'Process group ID to scope the search (optional for search). Defaults to root.',
                    required: false,
                ),
                new StringParameter(
                    'limit',
                    'Maximum number of results (optional for history). Default: 50.',
                    required: false,
                ),
                new StringParameter(
                    'offset',
                    'Pagination offset (optional for history). Default: 0.',
                    required: false,
                ),
                new StringParameter(
                    'sourceId',
                    'Filter history by source component ID (optional for history).',
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
            'status' => $this->getStatus(),
            'search' => $this->search($args),
            'history' => $this->getHistory($args),
            'bulletin_board' => $this->getBulletinBoard(),
            'diagnostics' => $this->getDiagnostics(),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function getStatus(): ToolResult
    {
        return $this->client->get('flow/status')
            ->toToolResultWith('NiFi flow status:');
    }

    /** @param array<string, mixed> $args */
    private function search(array $args): ToolResult
    {
        $query = $this->requireString($args, 'query');
        if ($query === null) {
            return ToolResult::error('query is required for the "search" action.');
        }

        $params = ['q' => $query];

        $groupId = $this->optionalString($args, 'groupId');
        if ($groupId !== null) {
            $params['processGroupId'] = $groupId;
        }

        return $this->client->get('flow/search-results', $params)
            ->toToolResultWith("Search results for '{$query}':");
    }

    /** @param array<string, mixed> $args */
    private function getHistory(array $args): ToolResult
    {
        $params = [
            'count' => $this->optionalString($args, 'limit') ?? '50',
            'offset' => $this->optionalString($args, 'offset') ?? '0',
            'sortColumn' => 'timestamp',
            'sortOrder' => 'desc',
        ];

        $sourceId = $this->optionalString($args, 'sourceId');
        if ($sourceId !== null) {
            $params['sourceId'] = $sourceId;
        }

        return $this->client->get('flow/history', $params)
            ->toToolResultWith('Flow history:');
    }

    private function getBulletinBoard(): ToolResult
    {
        return $this->client->get('flow/bulletin-board')
            ->toToolResultWith('Bulletin board (warnings/errors):');
    }

    private function getDiagnostics(): ToolResult
    {
        return $this->client->get('system-diagnostics')
            ->toToolResultWith('System diagnostics:');
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
