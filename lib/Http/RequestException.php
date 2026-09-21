<?php

namespace Bx\Imshop\Integration\Http;

/**
 * HTTP error that the front controller turns into a JSON response.
 */
final class RequestException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
