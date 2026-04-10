<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

final readonly class ParameterContext
{
    /** @param array<string, ParameterValue> $parameters */
    public function __construct(
        public string $name,
        public array $parameters = [],
        public string $description = '',
        public ?string $id = null,
    ) {}

    public function withParameter(string $name, string $value, bool $sensitive = false): self
    {
        $params = $this->parameters;
        $params[$name] = new ParameterValue($value, $sensitive);

        return new self(
            name: $this->name,
            parameters: $params,
            description: $this->description,
            id: $this->id,
        );
    }

    public function withId(string $id): self
    {
        return new self(
            name: $this->name,
            parameters: $this->parameters,
            description: $this->description,
            id: $id,
        );
    }
}