<?php

namespace App\Services\Acceleration;

class NodeDeploymentVerifier
{
    public function verify(string $releaseId): DeploymentEvidence
    {
        // No verified node adapter exists yet. A pull or client ACK is not evidence.
        return new DeploymentEvidence([], now(), false);
    }
}
