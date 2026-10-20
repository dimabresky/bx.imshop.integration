<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\StoreMap;

/**
 * GET /local/imshop/stores
 */
final class StoreMapWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly StoreMap $stores = new StoreMap(),
    ) {
    }

    public function code(): string
    {
        return 'stores';
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
        return 'Не удалось получить магазины';
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, string>>
     */
    public function handle(array $payload): array
    {
        return $this->stores->list();
    }
}
