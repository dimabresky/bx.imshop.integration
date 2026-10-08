<?php

namespace Bx\Imshop\Integration\Webhook;

/**
 * One IMSHOP webhook.
 * code() is the path under /local/imshop/.
 * responseKey() is the primary JSON list key.
 * responseListKeys() are cleared on error. failureMessage() is the 500 text.
 */
interface WebhookHandlerInterface
{
    public function code(): string;

    public function responseKey(): string;

    /**
     * @return list<string>
     */
    public function responseListKeys(): array;

    public function failureMessage(): string;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array;
}
