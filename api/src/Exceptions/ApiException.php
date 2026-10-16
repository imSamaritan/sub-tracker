<?php
declare(strict_types=1);

namespace SubTracker\Exceptions;

use Exception;
use Throwable;

class ApiException extends Exception
{
    public function __construct(
        string $message,
        protected readonly string $errorCode = 'SERVER_ERROR',
        protected readonly int $statusCode = 500,
        protected readonly ?array $fields = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getFields(): ?array
    {
        return $this->fields;
    }
}
