<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\User\PhoneCode;

/**
 * POST /local/imshop/users/otp/confirm
 */
final class UserOtpConfirmWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly PhoneCode $codes = new PhoneCode(),
    ) {
    }

    public function code(): string
    {
        return 'users/otp/confirm';
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
        return $this->codes->confirm($payload);
    }
}
