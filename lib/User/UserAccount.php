<?php

namespace Bx\Imshop\Integration\User;

use Bitrix\Main\UserTable;
use CUser;

/**
 * Reads and updates the Bitrix user behind an IMSHOP userIdentifier.
 * Legal entities and agreements are accepted by the webhook and not stored.
 */
final class UserAccount
{
    public function __construct(
        private readonly PhoneUserFinder $phones = new PhoneUserFinder(),
        private readonly UserProfile $profile = new UserProfile(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function get(array $payload): array
    {
        $resolved = $this->resolve($payload);
        if (!($resolved['ok'] ?? false)) {
            return $resolved['error'];
        }

        return [
            'user' => $this->profile->present($resolved['userId']),
        ];
    }

    /**
     * Updates only keys present in the payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(array $payload): array
    {
        $resolved = $this->resolve($payload);
        if (!($resolved['ok'] ?? false)) {
            return $resolved['error'];
        }

        $userId = $resolved['userId'];
        $fields = [];
        $phone = null;

        if (array_key_exists('name', $payload)) {
            $name = $this->text($payload['name']);
            if ($name === '') {
                return $this->error('Укажите имя');
            }
            [$firstName, $lastName, $secondName] = $this->profile->splitName($name);
            $fields['NAME'] = $firstName;
            $fields['LAST_NAME'] = $lastName;
            $fields['SECOND_NAME'] = $secondName;
        }

        if (array_key_exists('email', $payload)) {
            $email = $this->text($payload['email']);
            if ($email === '') {
                return $this->error('Укажите email');
            }
            if (!check_email($email)) {
                return $this->error('Некорректный email');
            }
            $fields['EMAIL'] = $email;
        }

        if (array_key_exists('birthday', $payload)) {
            $raw = $payload['birthday'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $fields['PERSONAL_BIRTHDAY'] = '';
            } else {
                $birthday = $this->profile->birthdayField($raw);
                if (!is_string($birthday)) {
                    return $this->error('Некорректная дата рождения');
                }
                $fields['PERSONAL_BIRTHDAY'] = $birthday;
            }
        }

        if (array_key_exists('phone', $payload)) {
            $phone = $this->phones->normalize($this->text($payload['phone']));
            if ($phone === '') {
                return $this->error(
                    trim($this->text($payload['phone'])) === ''
                        ? 'Не указан номер телефона'
                        : 'Некорректный номер телефона'
                );
            }
            $fields['PHONE_NUMBER'] = $phone;
            $fields['PERSONAL_PHONE'] = $phone;
        }

        if ($fields === []) {
            return [
                'user' => $this->profile->present($userId),
            ];
        }

        $saved = $phone === null
            ? $this->save($userId, $fields)
            : $this->savePhone($userId, $phone, $fields);
        if ($saved !== null) {
            return $this->error($saved);
        }

        return [
            'user' => $this->profile->present($userId, $phone ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: true, userId: int}|array{ok: false, error: array{error: array{message: string}}}
     */
    public function resolve(array $payload): array
    {
        $raw = $this->text($payload['userIdentifier'] ?? null);
        if ($raw === '') {
            return ['ok' => false, 'error' => $this->error('Не указан идентификатор пользователя')];
        }
        if (preg_match('/^[1-9]\d*$/', $raw) !== 1) {
            return ['ok' => false, 'error' => $this->error('Пользователя с таким идентификатором более не существует')];
        }

        $userId = (int) $raw;
        $row = UserTable::getRow([
            'select' => ['ID', 'ACTIVE'],
            'filter' => ['=ID' => $userId],
        ]);
        if (!is_array($row) || (string) ($row['ACTIVE'] ?? 'N') !== 'Y') {
            return ['ok' => false, 'error' => $this->error('Пользователя с таким идентификатором более не существует')];
        }

        return ['ok' => true, 'userId' => $userId];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function savePhone(int $userId, string $phone, array $fields): ?string
    {
        if (!$this->phones->lock($phone)) {
            return 'Не удалось сохранить профиль';
        }

        try {
            $found = $this->phones->find($phone);
            if (
                $found['kind'] === 'ambiguous'
                || (
                    ($found['kind'] === 'auth' || $found['kind'] === 'profile')
                    && $found['userId'] !== $userId
                )
            ) {
                return 'Номер уже используется';
            }

            return $this->save($userId, $fields);
        } finally {
            $this->phones->unlock($phone);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(int $userId, array $fields): ?string
    {
        $user = new CUser();
        if ($user->Update($userId, $fields)) {
            return null;
        }

        return $this->plainError($user->LAST_ERROR, 'Не удалось сохранить профиль');
    }

    /**
     * @return array{error: array{message: string}}
     */
    private function error(string $message): array
    {
        return [
            'error' => [
                'message' => $message,
            ],
        ];
    }

    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }

    private function plainError(mixed $lastError, string $fallback): string
    {
        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $lastError);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return $text !== '' ? $text : $fallback;
    }
}
