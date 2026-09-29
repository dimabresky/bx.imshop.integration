<?php

namespace Bx\Imshop\Integration\Webhook;

/**
 * One IMSHOP webhook.
 * code() is the path under /local/imshop/. responseKey() is the JSON list key.
 */
interface WebhookHandlerInterface
{
    public function code(): string;

    public function responseKey(): string;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array;
}
