<?php

namespace App\Exceptions;

use RuntimeException;

class RoutingException extends RuntimeException
{
    protected int $httpStatus;
    protected string $errorCode;

    public function __construct(
        string $message,
        int $httpStatus = 502,
        string $errorCode = 'ROUTING_ERROR',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
