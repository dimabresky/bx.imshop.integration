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
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

        try {
            if ($method !== 'POST') {
                throw new RequestException('Ожидается POST', 405);
            }

            if (!Config::isEnabled()) {
                throw new RequestException('Интеграция IMSHOP выключена', 503);
            }

            $payload = $this->readPayload();
            RequestLogger::info(
                'request',
                RequestLogger::requestContext($webhookCode, $method, $payload)
            );
            AuthGuard::assert($payload);
            unset($payload['key']);

            $handler = $this->registry->get($webhookCode);
            if ($handler === null) {
                throw new RequestException('Webhook не найден', 404);
            }

            $response = $handler->handle($payload);
            JsonResponder::send($response);
            RequestLogger::info('response', [
                'webhook' => $webhookCode,
                'status' => 200,
                'body' => $response,
            ]);
        } catch (RequestException $exception) {
            RequestLogger::warning('problem', [
                'webhook' => $webhookCode,
                'method' => $method,
                'status' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
            ]);
            JsonResponder::send(
                $this->errorPayload($webhookCode, $exception->getMessage()),
                $exception->getStatusCode()
            );
        } catch (\Throwable $exception) {
            RequestLogger::error('problem', [
                'webhook' => $webhookCode,
                'method' => $method,
                'status' => 500,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            JsonResponder::send(
                $this->errorPayload($webhookCode, $this->failureMessage($webhookCode)),
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
        if ($webhookCode === 'deliveries' || $webhookCode === 'payments') {
            return [
                $webhookCode => [],
                'message' => $message,
            ];
        }

        return ['message' => $message];
    }

    private function failureMessage(string $webhookCode): string
    {
        if ($webhookCode === 'payments') {
            return 'Не удалось рассчитать способы оплаты';
        }

        return 'Не удалось рассчитать доставку';
    }
}
