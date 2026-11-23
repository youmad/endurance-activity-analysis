<?php

declare(strict_types=1);

namespace Endurance\ActivityAnalysis;

final readonly class TrackStep
{
    public function __construct(public float $distanceMetres, public ?float $speedMetresPerSecond, public bool $hasDistance)
    {
    }
}
