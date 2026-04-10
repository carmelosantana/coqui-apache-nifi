<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

/**
 * Represents a NiFi Controller Service.
 */
final readonly class ControllerService
{
    /** @param array<string, string> $properties */
    public function __construct(
        public string $name,
        public string $type,
        public array $properties = [],
        public ?string $id = null,
    ) {}

    public function withId(string $id): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            properties: $this->properties,
            id: $id,
        );
    }
}