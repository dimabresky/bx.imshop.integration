<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\BePaidAcquiring;

/**
 * POST /local/imshop/payments/create
 */
final class PaymentCreateWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly BePaidAcquiring $acquiring = new BePaidAcquiring(),
    ) {
    }

    public function code(): string
    {
        return 'payments/create';
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
        return 'Не удалось создать платёж';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->acquiring->create($payload);
    }
}
