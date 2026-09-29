<?php

namespace Bx\Imshop\Integration\Http;

use Bitrix\Main\Application;
use Bitrix\Main\HttpRequest;
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
        $header = self::authorizationHeader();
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $matches) === 1) {
            return $matches[1];
        }

        $bodyKey = $payload['key'] ?? null;

        return is_string($bodyKey) ? trim($bodyKey) : '';
    }

    /**
     * HttpRequest copies only $_SERVER HTTP_* keys. Apache often omits Authorization there
     * while getallheaders() still returns it.
     */
    private static function authorizationHeader(): string
    {
        $request = Application::getInstance()->getContext()->getRequest();
        if ($request instanceof HttpRequest) {
            $header = $request->getHeader('Authorization');
            if (is_string($header) && trim($header) !== '') {
                return trim($header);
            }
        }

        if (!function_exists('getallheaders')) {
            return '';
        }

        $headers = getallheaders();
        if (!is_array($headers)) {
            return '';
        }

        foreach ($headers as $name => $value) {
            if (!is_string($name) || strcasecmp($name, 'Authorization') !== 0) {
                continue;
            }
            if (!is_string($value) && !is_numeric($value)) {
                return '';
            }

            return trim((string) $value);
        }

        $redirected = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (is_string($redirected) && trim($redirected) !== '') {
            return trim($redirected);
        }

        return '';
    }
}
