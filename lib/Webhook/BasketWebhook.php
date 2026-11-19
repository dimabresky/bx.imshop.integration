<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\BasketCalculator;

/**
 * POST /local/imshop/basket
 */
final class BasketWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly BasketCalculator $calculator = new BasketCalculator(),
    ) {
    }

    public function code(): string
    {
        return 'basket';
    }

    public function responseKey(): string
    {
        return 'items';
    }

    /**
     * @return list<string>
     */
    public function responseListKeys(): array
    {
        return ['items'];
    }

    public function failureMessage(): string
    {
        return 'Не удалось рассчитать корзину';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->calculator->calculate($payload);
    }
}
