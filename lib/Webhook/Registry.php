<?php

namespace Bx\Imshop\Integration\Webhook;

/**
 * Webhook code to handler. A new integration is a new class registered here.
 */
final class Registry
{
    /** @var array<string, WebhookHandlerInterface> */
    private array $handlers;

    public function __construct()
    {
        $delivery = new DeliveryWebhook();
        $payment = new PaymentWebhook();
        $order = new OrderWebhook();
        $this->handlers = [
            $delivery->code() => $delivery,
            $payment->code() => $payment,
            $order->code() => $order,
        ];
    }

    public function get(string $code): ?WebhookHandlerInterface
    {
        return $this->handlers[$code] ?? null;
    }
}
