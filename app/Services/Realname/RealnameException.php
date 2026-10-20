<?php

namespace App\Services\Realname;

use RuntimeException;

class RealnameException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }

    public function render()
    {
        return response()->json([
            'status' => 'fail',
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'data' => null,
        ], $this->getCode())->header('Cache-Control', 'no-store');
    }

    public function report(): bool
    {
        return true; // Never report upstream exception objects, parameters, or links.
    }
}
