<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\DeliveryCalculator;

/**
 * POST /local/imshop/deliveries
 */
final class DeliveryWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly DeliveryCalculator $calculator = new DeliveryCalculator(),
    ) {
    }

    public function code(): string
    {
        return 'deliveries';
    }

    public function responseKey(): string
    {
        return $this->code();
    }

    /**
     * @return list<string>
     */
    public function responseListKeys(): array
    {
        return [$this->responseKey()];
    }

    public function failureMessage(): string
    {
        return 'Не удалось рассчитать доставку';
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
