<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

final readonly class Port
{
    /** @param 'INPUT'|'OUTPUT' $portType */
    public function __construct(
        public string $name,
        public string $portType,
        public ?Position $position = null,
        public ?string $id = null,
    ) {}

    public static function input(string $name): self
    {
        return new self(name: $name, portType: 'INPUT');
    }

    public static function output(string $name): self
    {
        return new self(name: $name, portType: 'OUTPUT');
    }

    public function withId(string $id): self
    {
        return new self(
            name: $this->name,
            portType: $this->portType,
            position: $this->position,
            id: $id,
        );
    }
}