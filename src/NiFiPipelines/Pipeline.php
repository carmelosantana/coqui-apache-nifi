<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

final readonly class Pipeline
{
    /**
     * @param Processor[] $processors
     * @param Connection[] $connections
     * @param ControllerService[] $controllerServices
     * @param ParameterContext[] $parameterContexts
     * @param Port[] $ports
     */
    public function __construct(
        public string $name,
        public array $processors = [],
        public array $connections = [],
        public array $controllerServices = [],
        public array $parameterContexts = [],
        public array $ports = [],
        public ?Position $position = null,
        public ?string $parentGroupId = null,
    ) {}

    public static function create(string $name): PipelineBuilder
    {
        return new PipelineBuilder($name);
    }

    public function processor(string $name): ?Processor
    {
        foreach ($this->processors as $processor) {
            if ($processor->name === $name) {
                return $processor;
            }
        }

        return null;
    }

    public function controllerService(string $name): ?ControllerService
    {
        foreach ($this->controllerServices as $service) {
            if ($service->name === $name) {
                return $service;
            }
        }

        return null;
    }

    public function parameterContext(string $name): ?ParameterContext
    {
        foreach ($this->parameterContexts as $context) {
            if ($context->name === $name) {
                return $context;
            }
        }

        return null;
    }
}