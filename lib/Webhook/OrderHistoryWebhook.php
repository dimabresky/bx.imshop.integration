<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\OrderHistory;

/**
 * POST /local/imshop/orders/history
 */
final class OrderHistoryWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly OrderHistory $history = new OrderHistory(),
    ) {
    }

    public function code(): string
    {
        return 'orders/history';
    }

    public function responseKey(): string
    {
        return 'orders';
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
        return 'Не удалось получить историю заказов';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->history->page($payload);
    }
}
