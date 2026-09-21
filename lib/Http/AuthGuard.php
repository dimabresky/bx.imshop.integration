<?php

namespace Bx\Imshop\Integration\Http;

use Bx\Imshop\Integration\Config;

/**
 * IMSHOP API key: Authorization Bearer or JSON field "key".
 */
final class AuthGuard
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function assert(array $payload): void
    {
        $expected = Config::getApiKey();
        if ($expected === '') {
            throw new RequestException('Ключ API не настроен', 401);
        }

        $provided = self::providedKey($payload);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new RequestException('Неверный ключ API', 401);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function providedKey(array $payload): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $matches) === 1) {
            return $matches[1];
        }

        $bodyKey = $payload['key'] ?? null;

        return is_string($bodyKey) ? trim($bodyKey) : '';
    }
}
