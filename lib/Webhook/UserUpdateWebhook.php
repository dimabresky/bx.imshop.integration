<?php

namespace Bx\Imshop\Integration\Webhook;

use Bx\Imshop\Integration\User\UserAccount;

/**
 * PATCH /local/imshop/users/update
 */
final class UserUpdateWebhook implements WebhookHandlerInterface
{
    public function __construct(
        private readonly UserAccount $accounts = new UserAccount(),
    ) {
    }

    public function code(): string
    {
        return 'users/update';
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
        return 'Не удалось сохранить профиль';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->accounts->update($payload);
    }
}
