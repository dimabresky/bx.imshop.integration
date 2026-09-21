<?php

namespace Bx\Imshop\Integration\Webhook;

/**
 * One IMSHOP webhook. The code matches the directory under /local/imshop/.
 */
interface WebhookHandlerInterface
{
    public function code(): string;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array;
}
