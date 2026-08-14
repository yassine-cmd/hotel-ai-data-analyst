<?php

namespace App\Services;

class DataQueryException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly bool $retryable,
        private readonly string $queryId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getQueryId(): string
    {
        return $this->queryId;
    }
}
