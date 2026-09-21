<?php

namespace Bx\Imshop\Integration\Http;

/**
 * JSON body for IMSHOP webhook responses.
 */
final class JsonResponder
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function send(array $payload, int $statusCode = 200): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
