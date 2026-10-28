<?php

namespace App\Services\Acceleration;

use Carbon\CarbonInterface;

class DeploymentEvidence
{
    public function __construct(public readonly array $laneGenerations, public readonly CarbonInterface $observedAt, public readonly bool $passed) {}
}
