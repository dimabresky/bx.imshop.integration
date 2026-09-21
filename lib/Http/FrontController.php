<?php

namespace Bx\Imshop\Integration\Http;

use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Webhook\Registry;

/**
 * Stateless POST router for IMSHOP webhooks.
 */
final class FrontController
{
    public function __construct(
        private readonly Registry $registry = new Registry(),
    ) {
    }

    public function run(string $webhookCode): void
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RequestException('Ожидается POST', 405);
            }

            if (!Config::isEnabled()) {
                throw new RequestException('Интеграция IMSHOP выключена', 503);
            }

            $payload = $this->readPayload();
            AuthGuard::assert($payload);
            unset($payload['key']);

            $handler = $this->registry->get($webhookCode);
            if ($handler === null) {
                throw new RequestException('Webhook не найден', 404);
            }

            JsonResponder::send($handler->handle($payload));
        } catch (RequestException $exception) {
            JsonResponder::send(
                $this->errorPayload($webhookCode, $exception->getMessage()),
                $exception->getStatusCode()
            );
        } catch (\Throwable $exception) {
            AddMessage2Log(
                $exception->getMessage(),
                Config::MODULE_ID
            );
            JsonResponder::send(
                $this->errorPayload($webhookCode, 'Не удалось рассчитать доставку'),
                500
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            throw new RequestException('Пустое тело запроса', 400);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RequestException('Тело запроса должно быть JSON-объектом', 400);
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(string $webhookCode, string $message): array
    {
        if ($webhookCode === 'deliveries') {
            return [
                'deliveries' => [],
                'message' => $message,
            ];
        }

        return ['message' => $message];
    }
}
