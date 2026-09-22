<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\Sale\PaymentCalculator;

/**
 * POST /local/imshop/payments
 */
final class PaymentWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly PaymentCalculator $calculator = new PaymentCalculator(),
    ) {
    }

    public function code(): string
    {
        return 'payments';
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
