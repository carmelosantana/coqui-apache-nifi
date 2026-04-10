<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

final readonly class Processor
{
    /**
     * @param array<string, string> $properties
     * @param array<string, string> $scheduling
     * @param string[] $autoTerminatedRelationships
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $properties = [],
        public array $scheduling = [],
        public array $autoTerminatedRelationships = [],
        public ?Position $position = null,
        public ?string $id = null,
    ) {}

    public function withPosition(float $x, float $y): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            properties: $this->properties,
            scheduling: $this->scheduling,
            autoTerminatedRelationships: $this->autoTerminatedRelationships,
            position: new Position($x, $y),
            id: $this->id,
        );
    }

    public function withId(string $id): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            properties: $this->properties,
            scheduling: $this->scheduling,
            autoTerminatedRelationships: $this->autoTerminatedRelationships,
            position: $this->position,
            id: $id,
        );
    }

    /** @param array<string, string> $properties */
    public function withProperties(array $properties): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            properties: array_merge($this->properties, $properties),
            scheduling: $this->scheduling,
            autoTerminatedRelationships: $this->autoTerminatedRelationships,
            position: $this->position,
            id: $this->id,
        );
    }
}