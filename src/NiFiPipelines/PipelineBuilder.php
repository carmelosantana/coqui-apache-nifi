<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

final class PipelineBuilder
{
    /** @var Processor[] */
    private array $processors = [];

    /** @var Connection[] */
    private array $connections = [];

    /** @var ControllerService[] */
    private array $controllerServices = [];

    /** @var ParameterContext[] */
    private array $parameterContexts = [];

    /** @var Port[] */
    private array $ports = [];

    private ?string $parentGroupId = null;

    public function __construct(
        private readonly string $name,
    ) {}

    /**
     * @param array<string, string> $properties
     * @param array<string, string> $scheduling
     * @param string[] $autoTerminatedRelationships
     */
    public function addProcessor(
        string $name,
        string $type,
        array $properties = [],
        array $scheduling = [],
        array $autoTerminatedRelationships = [],
    ): self {
        $this->processors[] = new Processor(
            name: $name,
            type: $type,
            properties: $properties,
            scheduling: $scheduling,
            autoTerminatedRelationships: $autoTerminatedRelationships,
        );

        return $this;
    }

    /**
     * @param string[] $relationships
     * @param array<string, mixed> $backPressure
     */
    public function connect(
        string $sourceName,
        string $destinationName,
        array $relationships = ['success'],
        array $backPressure = [],
    ): self {
        $this->connections[] = new Connection(
            sourceName: $sourceName,
            destinationName: $destinationName,
            relationships: $relationships,
            backPressure: $backPressure,
        );

        return $this;
    }

    /** @param array<string, string> $properties */
    public function withControllerService(
        string $name,
        string $type,
        array $properties = [],
    ): self {
        $this->controllerServices[] = new ControllerService(
            name: $name,
            type: $type,
            properties: $properties,
        );

        return $this;
    }

    /** @param array<string, string> $parameters */
    public function withParameterContext(
        string $name,
        array $parameters = [],
        string $description = '',
    ): self {
        $values = [];
        foreach ($parameters as $key => $value) {
            $values[$key] = new ParameterValue($value);
        }

        $this->parameterContexts[] = new ParameterContext(
            name: $name,
            parameters: $values,
            description: $description,
        );

        return $this;
    }

    /** @param array<string, ParameterValue> $parameters */
    public function withParameterContextValues(
        string $name,
        array $parameters = [],
        string $description = '',
    ): self {
        $this->parameterContexts[] = new ParameterContext(
            name: $name,
            parameters: $parameters,
            description: $description,
        );

        return $this;
    }

    public function withInputPort(string $name): self
    {
        $this->ports[] = Port::input($name);

        return $this;
    }

    public function withOutputPort(string $name): self
    {
        $this->ports[] = Port::output($name);

        return $this;
    }

    public function inGroup(string $parentGroupId): self
    {
        $this->parentGroupId = $parentGroupId;

        return $this;
    }

    public function build(): Pipeline
    {
        return new Pipeline(
            name: $this->name,
            processors: $this->processors,
            connections: $this->connections,
            controllerServices: $this->controllerServices,
            parameterContexts: $this->parameterContexts,
            ports: $this->ports,
            parentGroupId: $this->parentGroupId,
        );
    }
}