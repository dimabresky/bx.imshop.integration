<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\User\PhoneOtp;

/**
 * POST /local/imshop/otp/confirm
 */
final class OtpConfirmWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly PhoneOtp $otp = new PhoneOtp(),
    ) {
    }

    public function code(): string
    {
        return 'otp/confirm';
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
        return 'Не удалось подтвердить код';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->otp->confirm($payload);
    }
}
