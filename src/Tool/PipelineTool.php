<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitApacheNifi\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\NiFiPipelines\Connection;
use CoquiBot\NiFiPipelines\ControllerService;
use CoquiBot\NiFiPipelines\NiFiPayloadConverter;
use CoquiBot\NiFiPipelines\ParameterContext;
use CoquiBot\NiFiPipelines\ParameterValue;
use CoquiBot\NiFiPipelines\Pipeline;
use CoquiBot\NiFiPipelines\Processor;
use CarmeloSantana\CoquiToolkitApacheNifi\Runtime\NiFiClient;
use CarmeloSantana\CoquiToolkitApacheNifi\Runtime\NiFiResult;

/**
 * Deploy pipeline definitions to NiFi — validates, previews, or deploys
 * complete data flows from a JSON pipeline definition.
 */
final readonly class PipelineTool
{
    public function __construct(
        private NiFiClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'nifi_pipeline',
            description: 'Deploy a complete pipeline to NiFi from a JSON definition. Supports validate (check definition), preview (show API payloads), and deploy (create all components in NiFi).',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['deploy', 'validate', 'preview'],
                    required: true,
                ),
                new StringParameter(
                    'definition',
                    'Pipeline definition as JSON. See guidelines for the expected schema.',
                    required: true,
                ),
                new StringParameter(
                    'parentGroupId',
                    'Parent process group ID to deploy into (default: "root").',
                    required: false,
                ),
                new StringParameter(
                    'startAfterDeploy',
                    'Whether to start all processors after deployment. "true" or "false" (default: "false").',
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
            'validate' => $this->validate($args),
            'preview' => $this->preview($args),
            'deploy' => $this->deploy($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /** @param array<string, mixed> $args */
    private function validate(array $args): ToolResult
    {
        $pipeline = $this->parsePipelineDefinition($args);
        if ($pipeline instanceof ToolResult) {
            return $pipeline;
        }

        $issues = $this->validatePipeline($pipeline);
        if ($issues !== []) {
            return ToolResult::error("Validation failed:\n- " . implode("\n- ", $issues));
        }

        $summary = sprintf(
            "Pipeline '%s' is valid.\n- Processors: %d\n- Connections: %d\n- Controller Services: %d\n- Parameter Contexts: %d\n- Ports: %d",
            $pipeline->name,
            count($pipeline->processors),
            count($pipeline->connections),
            count($pipeline->controllerServices),
            count($pipeline->parameterContexts),
            count($pipeline->ports),
        );

        return ToolResult::success($summary);
    }

    /** @param array<string, mixed> $args */
    private function preview(array $args): ToolResult
    {
        $pipeline = $this->parsePipelineDefinition($args);
        if ($pipeline instanceof ToolResult) {
            return $pipeline;
        }

        $converter = new NiFiPayloadConverter();
        $manifest = $converter->toDeploymentManifest($pipeline);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ToolResult::error('Failed to encode deployment manifest.');
        }

        return ToolResult::success("Deployment manifest for '{$pipeline->name}':\n\n{$json}");
    }

    /** @param array<string, mixed> $args */
    private function deploy(array $args): ToolResult
    {
        $pipeline = $this->parsePipelineDefinition($args);
        if ($pipeline instanceof ToolResult) {
            return $pipeline;
        }

        $issues = $this->validatePipeline($pipeline);
        if ($issues !== []) {
            return ToolResult::error("Validation failed:\n- " . implode("\n- ", $issues));
        }

        $parentGroupId = trim((string) ($args['parentGroupId'] ?? '')) ?: 'root';
        $startAfterDeploy = strtolower(trim((string) ($args['startAfterDeploy'] ?? ''))) === 'true';
        $converter = new NiFiPayloadConverter();
        $log = [];

        // 1. Create parameter contexts
        $contextIdMap = [];
        foreach ($pipeline->parameterContexts as $context) {
            $payload = $converter->toParameterContextPayload($context);
            $result = $this->client->post('parameter-contexts', $payload);
            if (!$result->success) {
                return ToolResult::error("Failed to create parameter context '{$context->name}': " . $result->errorMessage());
            }
            $id = $this->extractId($result, 'id');
            $contextIdMap[$context->name] = $id;
            $log[] = "Created parameter context '{$context->name}' (ID: {$id})";
        }

        // 2. Create the process group
        $pgPayload = $converter->toProcessGroupPayload($pipeline);
        $pgResult = $this->client->post("process-groups/{$parentGroupId}/process-groups", $pgPayload);
        if (!$pgResult->success) {
            return ToolResult::error("Failed to create process group '{$pipeline->name}': " . $pgResult->errorMessage());
        }
        $groupId = $this->extractId($pgResult, 'id');
        $log[] = "Created process group '{$pipeline->name}' (ID: {$groupId})";

        // Bind parameter context if we created one
        if ($contextIdMap !== []) {
            $firstContextId = reset($contextIdMap);
            // Fetch current revision
            $pgCurrent = $this->client->get("process-groups/{$groupId}");
            if ($pgCurrent->success && is_array($pgCurrent->data)) {
                $this->client->put("process-groups/{$groupId}", [
                    'revision' => $pgCurrent->data['revision'] ?? ['version' => 0],
                    'component' => [
                        'id' => $groupId,
                        'parameterContext' => ['id' => $firstContextId],
                    ],
                ]);
                $log[] = "Bound parameter context to process group";
            }
        }

        // 3. Create controller services
        $serviceIdMap = [];
        foreach ($pipeline->controllerServices as $service) {
            $payload = $converter->toControllerServicePayload($service);
            $result = $this->client->post("process-groups/{$groupId}/controller-services", $payload);
            if (!$result->success) {
                return ToolResult::error("Failed to create controller service '{$service->name}': " . $result->errorMessage());
            }
            $id = $this->extractId($result, 'id');
            $serviceIdMap[$service->name] = $id;
            $log[] = "Created controller service '{$service->name}' (ID: {$id})";
        }

        // 4. Create processors
        $processorIdMap = [];
        foreach ($pipeline->processors as $index => $processor) {
            $payload = $converter->toProcessorPayload($processor, $index);
            $result = $this->client->post("process-groups/{$groupId}/processors", $payload);
            if (!$result->success) {
                return ToolResult::error("Failed to create processor '{$processor->name}': " . $result->errorMessage());
            }
            $id = $this->extractId($result, 'id');
            $processorIdMap[$processor->name] = $id;
            $log[] = "Created processor '{$processor->name}' (ID: {$id})";
        }

        // 5. Create connections (resolve source/destination names to IDs)
        foreach ($pipeline->connections as $connection) {
            $sourceId = $processorIdMap[$connection->sourceName] ?? null;
            $destinationId = $processorIdMap[$connection->destinationName] ?? null;

            if ($sourceId === null) {
                return ToolResult::error("Connection source '{$connection->sourceName}' not found in deployed processors.");
            }
            if ($destinationId === null) {
                return ToolResult::error("Connection destination '{$connection->destinationName}' not found in deployed processors.");
            }

            $resolved = $connection->withIds($sourceId, $destinationId);
            $payload = $converter->toConnectionPayload($resolved);
            $result = $this->client->post("process-groups/{$groupId}/connections", $payload);
            if (!$result->success) {
                return ToolResult::error(
                    "Failed to create connection '{$connection->sourceName}' → '{$connection->destinationName}': " . $result->errorMessage(),
                );
            }
            $log[] = "Connected '{$connection->sourceName}' → '{$connection->destinationName}'";
        }

        // 6. Enable controller services
        foreach ($serviceIdMap as $serviceName => $serviceId) {
            $serviceCurrent = $this->client->get("controller-services/{$serviceId}");
            if ($serviceCurrent->success && is_array($serviceCurrent->data)) {
                $this->client->put("controller-services/{$serviceId}/run-status", [
                    'revision' => $serviceCurrent->data['revision'] ?? ['version' => 0],
                    'state' => 'ENABLED',
                ]);
                $log[] = "Enabled controller service '{$serviceName}'";
            }
        }

        // 7. Optionally start all processors
        if ($startAfterDeploy) {
            $this->client->put("flow/process-groups/{$groupId}", [
                'id' => $groupId,
                'state' => 'RUNNING',
            ]);
            $log[] = "Started all processors in group";
        }

        return ToolResult::success(
            "Pipeline '{$pipeline->name}' deployed successfully to group '{$groupId}'.\n\n"
            . implode("\n", $log),
        );
    }

    /**
     * Parse the pipeline JSON definition into a Pipeline object.
     *
     * @param array<string, mixed> $args
     */
    private function parsePipelineDefinition(array $args): Pipeline|ToolResult
    {
        $raw = trim((string) ($args['definition'] ?? ''));
        if ($raw === '') {
            return ToolResult::error('definition is required.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ToolResult::error('definition must be a valid JSON object.');
        }

        $name = (string) ($data['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Pipeline definition must include a "name" field.');
        }

        // Parse processors
        $processors = [];
        foreach ($data['processors'] ?? [] as $p) {
            if (!is_array($p) || !isset($p['type'])) {
                return ToolResult::error('Each processor must have a "type" field.');
            }
            $processors[] = new Processor(
                name: (string) ($p['name'] ?? $this->shortClassName((string) $p['type'])),
                type: (string) $p['type'],
                properties: is_array($p['properties'] ?? null) ? $p['properties'] : [],
                scheduling: is_array($p['scheduling'] ?? null) ? $p['scheduling'] : [],
                autoTerminatedRelationships: is_array($p['autoTerminatedRelationships'] ?? null) ? $p['autoTerminatedRelationships'] : [],
            );
        }

        // Parse connections
        $connections = [];
        foreach ($data['connections'] ?? [] as $c) {
            if (!is_array($c) || !isset($c['source'], $c['destination'])) {
                return ToolResult::error('Each connection must have "source" and "destination" fields.');
            }
            $connections[] = new Connection(
                sourceName: (string) $c['source'],
                destinationName: (string) $c['destination'],
                relationships: is_array($c['relationships'] ?? null) ? $c['relationships'] : ['success'],
            );
        }

        // Parse controller services
        $services = [];
        foreach ($data['controllerServices'] ?? [] as $s) {
            if (!is_array($s) || !isset($s['type'])) {
                return ToolResult::error('Each controller service must have a "type" field.');
            }
            $services[] = new ControllerService(
                name: (string) ($s['name'] ?? $this->shortClassName((string) $s['type'])),
                type: (string) $s['type'],
                properties: is_array($s['properties'] ?? null) ? $s['properties'] : [],
            );
        }

        // Parse parameter contexts
        $contexts = [];
        foreach ($data['parameterContexts'] ?? [] as $ctx) {
            if (!is_array($ctx) || !isset($ctx['name'])) {
                return ToolResult::error('Each parameter context must have a "name" field.');
            }
            $params = [];
            foreach ($ctx['parameters'] ?? [] as $pName => $pValue) {
                if (is_array($pValue)) {
                    $params[(string) $pName] = new ParameterValue(
                        value: (string) ($pValue['value'] ?? ''),
                        sensitive: (bool) ($pValue['sensitive'] ?? false),
                    );
                } else {
                    $params[(string) $pName] = new ParameterValue(value: (string) $pValue);
                }
            }
            $contexts[] = new ParameterContext(
                name: (string) $ctx['name'],
                parameters: $params,
                description: (string) ($ctx['description'] ?? ''),
            );
        }

        return new Pipeline(
            name: $name,
            processors: $processors,
            connections: $connections,
            controllerServices: $services,
            parameterContexts: $contexts,
            parentGroupId: trim((string) ($args['parentGroupId'] ?? '')) ?: null,
        );
    }

    /**
     * Validate a pipeline definition for common issues.
     *
     * @return string[] List of validation issues (empty = valid)
     */
    private function validatePipeline(Pipeline $pipeline): array
    {
        $issues = [];

        if ($pipeline->processors === []) {
            $issues[] = 'Pipeline must contain at least one processor.';
        }

        $processorNames = [];
        foreach ($pipeline->processors as $processor) {
            if (isset($processorNames[$processor->name])) {
                $issues[] = "Duplicate processor name: '{$processor->name}'.";
            }
            $processorNames[$processor->name] = true;

            if ($processor->type === '') {
                $issues[] = "Processor '{$processor->name}' is missing a type.";
            }
        }

        foreach ($pipeline->connections as $connection) {
            if (!isset($processorNames[$connection->sourceName])) {
                $issues[] = "Connection source '{$connection->sourceName}' does not match any processor name.";
            }
            if (!isset($processorNames[$connection->destinationName])) {
                $issues[] = "Connection destination '{$connection->destinationName}' does not match any processor name.";
            }
            if ($connection->relationships === []) {
                $issues[] = "Connection '{$connection->sourceName}' → '{$connection->destinationName}' has no relationships.";
            }
        }

        $serviceNames = [];
        foreach ($pipeline->controllerServices as $service) {
            if (isset($serviceNames[$service->name])) {
                $issues[] = "Duplicate controller service name: '{$service->name}'.";
            }
            $serviceNames[$service->name] = true;
        }

        $contextNames = [];
        foreach ($pipeline->parameterContexts as $context) {
            if (isset($contextNames[$context->name])) {
                $issues[] = "Duplicate parameter context name: '{$context->name}'.";
            }
            $contextNames[$context->name] = true;
        }

        return $issues;
    }

    /**
     * Extract an ID from a NiFi API response.
     */
    private function extractId(NiFiResult $result, string $key): string
    {
        if (!is_array($result->data)) {
            return '';
        }

        // NiFi responses nest the ID under various structures
        return (string) (
            $result->data[$key]
            ?? $result->data['component'][$key]
            ?? $result->data['id']
            ?? ''
        );
    }

    private function shortClassName(string $fqcn): string
    {
        $parts = explode('.', $fqcn);
        return end($parts) ?: $fqcn;
    }
}
