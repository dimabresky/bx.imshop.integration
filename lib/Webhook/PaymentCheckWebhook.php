<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\BePaidAcquiring;

/**
 * POST /local/imshop/payments/check
 */
final class PaymentCheckWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly BePaidAcquiring $acquiring = new BePaidAcquiring(),
    ) {
    }

    public function code(): string
    {
        return 'payments/check';
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
        return 'Не удалось проверить оплату';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->acquiring->capture($payload);
    }
}
