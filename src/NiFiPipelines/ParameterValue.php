<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

final readonly class ParameterValue
{
    public function __construct(
        public string $value,
        public bool $sensitive = false,
        public string $description = '',
    ) {}
}