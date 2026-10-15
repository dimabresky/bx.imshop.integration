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
        $paymentCreate = new PaymentCreateWebhook();
        $paymentCheck = new PaymentCheckWebhook();
        $order = new OrderWebhook();
        $availability = new CheckQuantityWebhook();
        $basket = new BasketWebhook();
        $otp = new OtpRequestWebhook();
        $otpConfirm = new OtpConfirmWebhook();
        $this->handlers = [
            $delivery->code() => $delivery,
            $payment->code() => $payment,
            $paymentCreate->code() => $paymentCreate,
            $paymentCheck->code() => $paymentCheck,
            $order->code() => $order,
            $availability->code() => $availability,
            $basket->code() => $basket,
            $otp->code() => $otp,
            $otpConfirm->code() => $otpConfirm,
        ];
    }

    public function get(string $code): ?WebhookHandlerInterface
    {
        return $this->handlers[$code] ?? null;
    }
}
