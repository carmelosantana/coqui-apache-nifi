<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

final class NiFiPayloadConverter
{
    private const float GRID_SPACING_X = 400.0;
    private const float GRID_SPACING_Y = 200.0;
    private const int GRID_COLUMNS = 4;

    /** @return array<string, mixed> */
    public function toProcessGroupPayload(Pipeline $pipeline): array
    {
        $position = $pipeline->position ?? new Position(0.0, 0.0);

        return [
            'revision' => ['version' => 0],
            'component' => [
                'name' => $pipeline->name,
                'position' => [
                    'x' => $position->x,
                    'y' => $position->y,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function toProcessorPayload(Processor $processor, int $index = 0): array
    {
        $position = $processor->position ?? $this->gridPosition($index);

        $component = [
            'type' => $processor->type,
            'name' => $processor->name,
            'position' => [
                'x' => $position->x,
                'y' => $position->y,
            ],
            'config' => [],
        ];

        if ($processor->properties !== []) {
            $component['config']['properties'] = $processor->properties;
        }

        if ($processor->scheduling !== []) {
            if (isset($processor->scheduling['schedulingStrategy'])) {
                $component['config']['schedulingStrategy'] = $processor->scheduling['schedulingStrategy'];
            }
            if (isset($processor->scheduling['schedulingPeriod'])) {
                $component['config']['schedulingPeriod'] = $processor->scheduling['schedulingPeriod'];
            }
            if (isset($processor->scheduling['concurrentlySchedulableTaskCount'])) {
                $component['config']['concurrentlySchedulableTaskCount'] = $processor->scheduling['concurrentlySchedulableTaskCount'];
            }
            if (isset($processor->scheduling['penaltyDuration'])) {
                $component['config']['penaltyDuration'] = $processor->scheduling['penaltyDuration'];
            }
            if (isset($processor->scheduling['yieldDuration'])) {
                $component['config']['yieldDuration'] = $processor->scheduling['yieldDuration'];
            }
            if (isset($processor->scheduling['executionNode'])) {
                $component['config']['executionNode'] = $processor->scheduling['executionNode'];
            }
        }

        if ($processor->autoTerminatedRelationships !== []) {
            $component['config']['autoTerminatedRelationships'] = $processor->autoTerminatedRelationships;
        }

        return [
            'revision' => ['version' => 0],
            'component' => $component,
        ];
    }

    /** @return array<string, mixed> */
    public function toConnectionPayload(Connection $connection): array
    {
        $payload = [
            'revision' => ['version' => 0],
            'component' => [
                'source' => [
                    'id' => $connection->sourceId ?? '',
                    'type' => 'PROCESSOR',
                ],
                'destination' => [
                    'id' => $connection->destinationId ?? '',
                    'type' => 'PROCESSOR',
                ],
                'selectedRelationships' => $connection->relationships,
            ],
        ];

        if ($connection->backPressure !== []) {
            if (isset($connection->backPressure['objectThreshold'])) {
                $payload['component']['backPressureObjectThreshold'] = $connection->backPressure['objectThreshold'];
            }
            if (isset($connection->backPressure['dataSizeThreshold'])) {
                $payload['component']['backPressureDataSizeThreshold'] = $connection->backPressure['dataSizeThreshold'];
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function toControllerServicePayload(ControllerService $service): array
    {
        $component = [
            'type' => $service->type,
            'name' => $service->name,
        ];

        if ($service->properties !== []) {
            $component['properties'] = $service->properties;
        }

        return [
            'revision' => ['version' => 0],
            'component' => $component,
        ];
    }

    /** @return array<string, mixed> */
    public function toParameterContextPayload(ParameterContext $context): array
    {
        $parameters = [];
        foreach ($context->parameters as $name => $paramValue) {
            $param = [
                'parameter' => [
                    'name' => $name,
                    'value' => $paramValue->value,
                    'sensitive' => $paramValue->sensitive,
                ],
            ];
            if ($paramValue->description !== '') {
                $param['parameter']['description'] = $paramValue->description;
            }
            $parameters[] = $param;
        }

        $component = [
            'name' => $context->name,
            'parameters' => $parameters,
        ];

        if ($context->description !== '') {
            $component['description'] = $context->description;
        }

        return [
            'revision' => ['version' => 0],
            'component' => $component,
        ];
    }

    /** @return array<string, mixed> */
    public function toPortPayload(Port $port, int $index = 0): array
    {
        $position = $port->position ?? $this->gridPosition($index);

        return [
            'revision' => ['version' => 0],
            'component' => [
                'name' => $port->name,
                'type' => $port->portType === 'INPUT' ? 'INPUT_PORT' : 'OUTPUT_PORT',
                'position' => [
                    'x' => $position->x,
                    'y' => $position->y,
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     processGroup: array<string, mixed>,
     *     parameterContexts: array<int, array<string, mixed>>,
     *     controllerServices: array<int, array<string, mixed>>,
     *     processors: array<int, array<string, mixed>>,
     *     connections: array<int, array<string, mixed>>,
     *     ports: array<int, array<string, mixed>>
     * }
     */
    public function toDeploymentManifest(Pipeline $pipeline): array
    {
        $processors = [];
        foreach ($pipeline->processors as $index => $processor) {
            $processors[] = $this->toProcessorPayload($processor, $index);
        }

        $connections = [];
        foreach ($pipeline->connections as $connection) {
            $connections[] = $this->toConnectionPayload($connection);
        }

        $controllerServices = [];
        foreach ($pipeline->controllerServices as $service) {
            $controllerServices[] = $this->toControllerServicePayload($service);
        }

        $parameterContexts = [];
        foreach ($pipeline->parameterContexts as $context) {
            $parameterContexts[] = $this->toParameterContextPayload($context);
        }

        $ports = [];
        foreach ($pipeline->ports as $index => $port) {
            $ports[] = $this->toPortPayload($port, $index);
        }

        return [
            'processGroup' => $this->toProcessGroupPayload($pipeline),
            'parameterContexts' => $parameterContexts,
            'controllerServices' => $controllerServices,
            'processors' => $processors,
            'connections' => $connections,
            'ports' => $ports,
        ];
    }

    private function gridPosition(int $index): Position
    {
        $col = $index % self::GRID_COLUMNS;
        $row = intdiv($index, self::GRID_COLUMNS);

        return new Position(
            x: $col * self::GRID_SPACING_X,
            y: $row * self::GRID_SPACING_Y,
        );
    }
}