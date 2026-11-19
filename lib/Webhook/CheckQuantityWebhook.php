<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\AvailabilityCalculator;

/**
 * POST /local/imshop/availability
 */
final class CheckQuantityWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly AvailabilityCalculator $availability = new AvailabilityCalculator(),
    ) {
    }

    public function code(): string
    {
        return 'availability';
    }

    public function responseKey(): string
    {
        return 'availability';
    }

    /**
     * @return list<string>
     */
    public function responseListKeys(): array
    {
        return ['warehouses', 'availability'];
    }

    public function failureMessage(): string
    {
        return 'Не удалось проверить наличие';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->availability->calculate($payload);
    }
}
