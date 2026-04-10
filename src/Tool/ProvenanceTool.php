<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitApacheNifi\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitApacheNifi\Runtime\NiFiClient;
use CarmeloSantana\CoquiToolkitApacheNifi\Runtime\NiFiResult;

/**
 * NiFi data provenance — track data lineage and debug flow execution.
 */
final readonly class ProvenanceTool
{
    public function __construct(
        private NiFiClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'nifi_provenance',
            description: 'Query NiFi data provenance — search for provenance events, get event details, or trace data lineage through the flow.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['search', 'get_event', 'lineage'],
                    required: true,
                ),
                new StringParameter(
                    'eventId',
                    'Provenance event ID (required for get_event and lineage).',
                    required: false,
                ),
                new StringParameter(
                    'processorId',
                    'Filter events by processor ID (optional for search).',
                    required: false,
                ),
                new StringParameter(
                    'componentType',
                    'Filter by component type (optional for search).',
                    required: false,
                ),
                new StringParameter(
                    'flowFileUuid',
                    'Filter by FlowFile UUID (optional for search).',
                    required: false,
                ),
                new StringParameter(
                    'maxResults',
                    'Maximum number of results (optional for search). Default: 100.',
                    required: false,
                ),
                new StringParameter(
                    'startDate',
                    'Start date filter in ISO 8601 format (optional for search).',
                    required: false,
                ),
                new StringParameter(
                    'endDate',
                    'End date filter in ISO 8601 format (optional for search).',
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
            'search' => $this->searchProvenance($args),
            'get_event' => $this->getEvent($args),
            'lineage' => $this->getLineage($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /** @param array<string, mixed> $args */
    private function searchProvenance(array $args): ToolResult
    {
        $searchTerms = [];

        $processorId = $this->optionalString($args, 'processorId');
        if ($processorId !== null) {
            $searchTerms['ProcessorID'] = $processorId;
        }

        $componentType = $this->optionalString($args, 'componentType');
        if ($componentType !== null) {
            $searchTerms['ComponentType'] = $componentType;
        }

        $flowFileUuid = $this->optionalString($args, 'flowFileUuid');
        if ($flowFileUuid !== null) {
            $searchTerms['FlowFileUUID'] = $flowFileUuid;
        }

        $body = [
            'provenance' => [
                'request' => [
                    'maxResults' => (int) ($this->optionalString($args, 'maxResults') ?? '100'),
                    'searchTerms' => $searchTerms,
                ],
            ],
        ];

        $startDate = $this->optionalString($args, 'startDate');
        if ($startDate !== null) {
            $body['provenance']['request']['startDate'] = $startDate;
        }

        $endDate = $this->optionalString($args, 'endDate');
        if ($endDate !== null) {
            $body['provenance']['request']['endDate'] = $endDate;
        }

        // Submit provenance query (async)
        $result = $this->client->post('provenance', $body);
        if (!$result->success || !is_array($result->data)) {
            return $result->toToolResult();
        }

        $queryId = $result->data['provenance']['id'] ?? null;
        if ($queryId === null) {
            return ToolResult::error('Failed to submit provenance query.');
        }

        // Poll for completion (provenance queries are async)
        $maxAttempts = 5;
        $status = NiFiResult::error('Provenance query returned no results.');
        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = $this->client->get("provenance/{$queryId}");
            if (!$status->success || !is_array($status->data)) {
                break;
            }

            $finished = $status->data['provenance']['finished'] ?? false;
            if ($finished) {
                break;
            }

            // Brief delay between polls
            usleep(500_000);
        }

        // Clean up the provenance query
        $this->client->delete("provenance/{$queryId}");

        return $status->toToolResultWith('Provenance search results:');
    }

    /** @param array<string, mixed> $args */
    private function getEvent(array $args): ToolResult
    {
        $eventId = $this->requireString($args, 'eventId');
        if ($eventId === null) {
            return ToolResult::error('eventId is required for the "get_event" action.');
        }

        return $this->client->get("provenance-events/{$eventId}")
            ->toToolResultWith('Provenance event details:');
    }

    /** @param array<string, mixed> $args */
    private function getLineage(array $args): ToolResult
    {
        $eventId = $this->requireString($args, 'eventId');
        if ($eventId === null) {
            return ToolResult::error('eventId is required for the "lineage" action.');
        }

        // Submit lineage query
        $result = $this->client->post('provenance/lineage', [
            'lineage' => [
                'request' => [
                    'eventId' => (int) $eventId,
                    'lineageRequestType' => 'FLOWFILE',
                ],
            ],
        ]);

        if (!$result->success || !is_array($result->data)) {
            return $result->toToolResult();
        }

        $lineageId = $result->data['lineage']['id'] ?? null;
        if ($lineageId === null) {
            return ToolResult::error('Failed to submit lineage query.');
        }

        // Poll for completion
        $maxAttempts = 5;
        $status = NiFiResult::error('Lineage query returned no results.');
        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = $this->client->get("provenance/lineage/{$lineageId}");
            if (!$status->success || !is_array($status->data)) {
                break;
            }

            $finished = $status->data['lineage']['finished'] ?? false;
            if ($finished) {
                break;
            }

            usleep(500_000);
        }

        // Clean up
        $this->client->delete("provenance/lineage/{$lineageId}");

        return $status->toToolResultWith('Data lineage:');
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
