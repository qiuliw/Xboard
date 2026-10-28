<?php

namespace App\Services\Acceleration;

use Illuminate\Http\JsonResponse;

class AccelerationException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, int $httpStatus = 409)
    {
        parent::__construct($errorCode, $httpStatus);
    }

    public function render(): JsonResponse
    {
        return response()->json(['status' => 'fail', 'message' => $this->errorCode,
            'data' => null, 'error' => null, 'error_code' => $this->errorCode], $this->getCode());
    }

    public function report(): bool { return true; }
}
