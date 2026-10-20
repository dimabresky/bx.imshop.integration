<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\OrderStatusSync;

/**
 * POST /local/imshop/orders/status
 */
final class OrderStatusWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly OrderStatusSync $statuses = new OrderStatusSync(),
    ) {
    }

    public function code(): string
    {
        return 'orders/status';
    }

    public function responseKey(): string
    {
        return '';
    }

    /**
     * @return list<string>
     */
    public function responseListKeys(): array
    {
        return [];
    }

    public function failureMessage(): string
    {
        return 'Не удалось изменить статус заказа';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->statuses->apply($payload);
    }
}
