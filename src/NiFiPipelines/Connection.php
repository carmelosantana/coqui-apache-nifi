<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

/**
 * Represents a connection between two NiFi components.
 *
 * Maps to the NiFi Connection REST API resource.
 */
final readonly class Connection
{
    /**
     * @param string[] $relationships
     * @param array<string, mixed> $backPressure
     */
    public function __construct(
        public string $sourceName,
        public string $destinationName,
        public array $relationships = ['success'],
        public array $backPressure = [],
        public ?string $sourceId = null,
        public ?string $destinationId = null,
        public ?string $id = null,
    ) {}

    public function withIds(string $sourceId, string $destinationId): self
    {
        return new self(
            sourceName: $this->sourceName,
            destinationName: $this->destinationName,
            relationships: $this->relationships,
            backPressure: $this->backPressure,
            sourceId: $sourceId,
            destinationId: $destinationId,
            id: $this->id,
        );
    }
}