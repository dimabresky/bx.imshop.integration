<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\OrderCreator;

/**
 * POST /local/imshop/orders
 */
final class OrderWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly OrderCreator $orders = new OrderCreator(),
    ) {
    }

    public function code(): string
    {
        return 'orders';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->orders->place($payload);
    }
}
