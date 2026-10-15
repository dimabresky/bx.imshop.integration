<?php

namespace Bx\Imshop\Integration\User;

use Bitrix\Main\UserTable;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\RequestException;
use Bx\Imshop\Integration\Http\RequestLogger;
use Bx\Imshop\Integration\Sale\CalculationOrderFactory;
use CUser;

/**
 * Resolves the Bitrix user for an IMSHOP order.
 * A known externalUserId wins. Otherwise the phone finds or creates the buyer.
 */
final class OrderBuyer
{
    public function __construct(
        private readonly CalculationOrderFactory $orders = new CalculationOrderFactory(),
        private readonly PhoneUserFinder $phones = new PhoneUserFinder(),
    ) {
    }

    /**
     * @param array<string, mixed> $source
     */
    public function resolve(array $source): int
    {
        $known = $this->orders->userId($source);
        if ($known > 0) {
            return $known;
        }

        $normalized = $this->phones->normalize($this->text($source, 'phone'));
        $digits = $this->phones->digits($normalized);
        if ($normalized === '' || strlen($digits) < 9) {
            throw new RequestException('Не указан телефон покупателя', 200);
        }

        if (!$this->phones->lock($normalized)) {
            throw new RequestException('Не удалось определить покупателя', 500);
        }

        try {
            return $this->resolveLocked($source, $normalized, $digits);
        } finally {
            $this->phones->unlock($normalized);
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function resolveLocked(array $source, string $normalized, string $digits): int
    {
        $existing = $this->attachFound($normalized);
        if ($existing > 0) {
            return $existing;
        }

        return $this->register($source, $normalized, $digits);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function register(array $source, string $normalized, string $digits): int
    {
        [$name, $lastName, $secondName] = $this->splitName($this->text($source, 'name'));
        $email = $this->acceptableEmail($this->text($source, 'email'));
        $password = CUser::GeneratePasswordByPolicy([]);
        $fields = [
            'LOGIN' => $this->uniqueLogin($digits),
            'NAME' => $name,
            'LAST_NAME' => $lastName,
            'SECOND_NAME' => $secondName,
            'PASSWORD' => $password,
            'CONFIRM_PASSWORD' => $password,
            'ACTIVE' => 'Y',
            'LID' => Config::getSiteId(),
            'PHONE_NUMBER' => $normalized,
            'PERSONAL_PHONE' => $normalized,
        ];
        if ($email !== '') {
            $fields['EMAIL'] = $email;
        }

        $user = new CUser();
        $userId = (int) $user->Add($fields);
        if ($userId <= 0 && $email !== '' && $this->emailTaken((string) $user->LAST_ERROR)) {
            unset($fields['EMAIL']);
            $userId = (int) $user->Add($fields);
        }
        if ($userId <= 0) {
            $existing = $this->attachFound($normalized);
            if ($existing > 0) {
                return $existing;
            }

            throw new RequestException(
                $this->userError($user->LAST_ERROR, 'Не удалось определить покупателя'),
                500
            );
        }

        $this->phones->ensurePhoneAuthRow($userId, $normalized);
        $this->notifyRegistered($userId);

        return $userId;
    }

    /**
     * Binds a buyer already stored for this phone. Zero means the phone is still free.
     */
    private function attachFound(string $normalized): int
    {
        $found = $this->phones->find($normalized);
        if ($found['kind'] === 'ambiguous') {
            throw new RequestException('Найдено несколько пользователей с этим номером', 200);
        }
        if ($found['kind'] === 'auth') {
            return $found['userId'];
        }
        if ($found['kind'] === 'profile') {
            $this->phones->ensurePhoneAuthRow($found['userId'], $normalized);

            return $found['userId'];
        }

        return 0;
    }

    private function acceptableEmail(string $email): string
    {
        if ($email === '' || !check_email($email) || $this->isTechnicalEmail($email)) {
            return '';
        }

        return $email;
    }

    private function emailTaken(string $error): bool
    {
        $text = mb_strtolower($error);
        $mentionsEmail = str_contains($text, 'e-mail') || str_contains($text, 'email');
        $taken = str_contains($text, 'уже существует') || str_contains($text, 'already exist');

        return $mentionsEmail && $taken;
    }

    private function isTechnicalEmail(string $email): bool
    {
        $utils = \Dnk\PhpInterface\Utils::class;
        if (class_exists($utils)) {
            return $utils::isTechnicalBuyerEmail($email);
        }

        return preg_match('/^buyer[0-9]+/i', trim($email)) === 1;
    }

    /**
     * Фамилия, имя, отчество. Одно слово остаётся именем.
     *
     * @return array{0: string, 1: string, 2: string} NAME, LAST_NAME, SECOND_NAME
     */
    private function splitName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');
        $parts = $fullName === '' ? [] : explode(' ', $fullName);
        if (count($parts) <= 1) {
            return [$parts[0] ?? '', '', ''];
        }

        $lastName = array_shift($parts);
        $name = array_shift($parts);

        return [(string) $name, (string) $lastName, implode(' ', $parts)];
    }

    private function uniqueLogin(string $digits): string
    {
        if (!$this->loginExists($digits)) {
            return $digits;
        }

        for ($suffix = 1; $suffix <= 99; $suffix++) {
            $candidate = $digits . '_' . $suffix;
            if (!$this->loginExists($candidate)) {
                return $candidate;
            }
        }

        return $digits . '_' . bin2hex(random_bytes(4));
    }

    private function loginExists(string $login): bool
    {
        if ($login === '') {
            return false;
        }

        $row = UserTable::getRow([
            'select' => ['ID'],
            'filter' => ['=LOGIN' => $login],
        ]);

        return is_array($row);
    }

    private function notifyRegistered(int $userId): void
    {
        $class = \Dnk\PhpInterface\UserAddEvents::class;
        if (!class_exists($class)) {
            return;
        }

        try {
            $fields = ['USER_ID' => $userId, 'ID' => $userId];
            $class::onAfterUserRegister($fields);
        } catch (\Throwable $exception) {
            RequestLogger::error('order-buyer-register-hook', [
                'userId' => $userId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function text(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function userError(mixed $lastError, string $fallback): string
    {
        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $lastError);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return $text !== '' ? $text : $fallback;
    }
}
