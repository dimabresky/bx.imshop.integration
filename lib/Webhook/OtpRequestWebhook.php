<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\User\PhoneOtp;

/**
 * POST /local/imshop/otp
 */
final class OtpRequestWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly PhoneOtp $otp = new PhoneOtp(),
    ) {
    }

    public function code(): string
    {
        return 'otp';
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
        return 'Не удалось отправить код';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->otp->request($payload);
    }
}
