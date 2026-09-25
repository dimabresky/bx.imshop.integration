<?php

namespace Bx\Imshop\Integration\Http;

use Bx\Imshop\Integration\Config;

/**
 * Optional file log of webhook requests and failures.
 * Headers and the API key are written only when secret logging is enabled.
 */
final class RequestLogger
{
    private const HTACCESS = "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function requestContext(string $webhookCode, string $method, array $payload): array
    {
        $context = [
            'webhook' => $webhookCode,
            'method' => $method,
            'body' => self::redact($payload),
        ];
        if (Config::isSecretLoggingEnabled()) {
            $context['headers'] = self::headers();
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function redact(array $payload): array
    {
        if (!Config::isSecretLoggingEnabled()) {
            unset($payload['key']);
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $name => $value) {
                    if (is_string($name) && (is_string($value) || is_numeric($value))) {
                        $headers[$name] = (string) $value;
                    }
                }
            }
        }

        if ($headers === []) {
            foreach ($_SERVER as $name => $value) {
                if (!is_string($name) || !is_string($value) || !str_starts_with($name, 'HTTP_')) {
                    continue;
                }

                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$header] = $value;
            }
        }

        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authorization !== '' && !isset($headers['Authorization'])) {
            $headers['Authorization'] = $authorization;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        if (!Config::isLoggingEnabled()) {
            return;
        }

        $directory = self::directory();
        if ($directory === null) {
            return;
        }

        $record = [
            'time' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }

        file_put_contents(
            $directory . '/' . date('Y-m-d') . '.log',
            $line . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    private static function directory(): ?string
    {
        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot === '') {
            return null;
        }

        $directory = $documentRoot . '/upload/' . Config::MODULE_ID;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }

        $htaccess = $directory . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, self::HTACCESS, LOCK_EX);
        }

        return $directory;
    }
}
