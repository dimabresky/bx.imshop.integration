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
        $userGet = new UserGetWebhook();
        $userUpdate = new UserUpdateWebhook();
        $userOtp = new UserOtpRequestWebhook();
        $userOtpConfirm = new UserOtpConfirmWebhook();
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
            $userGet->code() => $userGet,
            $userUpdate->code() => $userUpdate,
            $userOtp->code() => $userOtp,
            $userOtpConfirm->code() => $userOtpConfirm,
        ];
    }

    public function get(string $code): ?WebhookHandlerInterface
    {
        return $this->handlers[$code] ?? null;
    }
}
