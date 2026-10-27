<?php

declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }
}
