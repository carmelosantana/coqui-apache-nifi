<?php

declare(strict_types=1);

namespace CoquiBot\NiFiPipelines;

final readonly class Position
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
    ) {}
}