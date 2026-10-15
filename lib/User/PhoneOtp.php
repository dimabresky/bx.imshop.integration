<?php

namespace Bx\Imshop\Integration\User;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Sms\Event as SmsEvent;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserPhoneAuthTable;
use Bitrix\Main\UserTable;
use Bitrix\Main\Security\Mfa\TotpAlgorithm;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\RequestLogger;
use CUser;

/**
 * Phone OTP for IMSHOP. Codes live in Bitrix UserPhoneAuthTable. The module has no own tables.
 */
final class PhoneOtp
{
    private const SMS_LOGIN = 'SMS_USER_AUTH_CODE';

    private const SMS_REGISTER = 'SMS_USER_CONFIRM_NUMBER';

    private const MAX_ATTEMPTS = 3;

    private const REPLY_TEXT = 'Выслать код повторно';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function request(array $payload): array
    {
        $blocked = $this->availabilityError();
        if ($blocked !== null) {
            return $this->error($blocked);
        }

        $normalized = $this->normalize($this->scalarString($payload['userIdentifier'] ?? null));
        if ($normalized === '') {
            return $this->error(
                trim($this->scalarString($payload['userIdentifier'] ?? null)) === ''
                    ? 'Не указан номер телефона'
                    : 'Некорректный номер телефона'
            );
        }

        $resolved = $this->resolveUserId($normalized);
        if (!($resolved['ok'] ?? false)) {
            return $this->error((string) ($resolved['error'] ?? 'Не удалось отправить код'));
        }

        $userId = $resolved['userId'] ?? null;
        if ($userId === null) {
            return $this->register($normalized, $payload);
        }

        $active = $this->isActive($userId);
        if ($active === null) {
            return $this->error('Не удалось отправить код');
        }

        return $this->sendCode($userId, !$active);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function confirm(array $payload): array
    {
        $blocked = $this->availabilityError();
        if ($blocked !== null) {
            return $this->error($blocked);
        }

        $normalized = $this->normalize($this->scalarString($payload['userIdentifier'] ?? null));
        if ($normalized === '') {
            return $this->error(
                trim($this->scalarString($payload['userIdentifier'] ?? null)) === ''
                    ? 'Не указан номер телефона'
                    : 'Некорректный номер телефона'
            );
        }

        $code = $this->scalarString($payload['otp'] ?? null);
        if ($code === '' || preg_match('/^\d+$/', $code) !== 1) {
            return $this->error('Неправильный код');
        }

        $resolved = $this->resolveUserId($normalized, false);
        if (!($resolved['ok'] ?? false) || ($resolved['userId'] ?? null) === null) {
            return $this->error(
                ($resolved['ok'] ?? false)
                    ? 'Неправильный код'
                    : (string) ($resolved['error'] ?? 'Неправильный код')
            );
        }

        $row = UserPhoneAuthTable::getRowById((int) $resolved['userId']);
        if (!is_array($row) || (string) ($row['PHONE_NUMBER'] ?? '') === '') {
            return $this->error('Неправильный код');
        }
        if ((int) ($row['ATTEMPTS'] ?? 0) >= self::MAX_ATTEMPTS) {
            return $this->error('Превышено количество попыток входа');
        }

        $userId = CUser::VerifyPhoneCode((string) $row['PHONE_NUMBER'], $code);
        if (!$userId) {
            return $this->error('Неправильный код');
        }

        $activationError = $this->activate((int) $userId);
        if ($activationError !== null) {
            return $this->error($activationError);
        }

        $this->notifyAuthorized((int) $userId);

        return [
            'user' => $this->profile((int) $userId, $normalized),
        ];
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

    private function availabilityError(): ?string
    {
        if (!Loader::includeModule('messageservice')) {
            return 'Авторизация по телефону недоступна';
        }

        if (Option::get('main', 'new_user_phone_auth', 'N') !== 'Y') {
            return 'Авторизация по телефону недоступна';
        }

        if (Loader::includeModule('aspro.premier') && class_exists(\Aspro\Premier\PhoneAuth::class)) {
            $options = \Aspro\Premier\PhoneAuth::getOptions();
            if (is_array($options) && array_key_exists(3, $options) && !$options[3]) {
                return 'Авторизация по телефону недоступна';
            }
        }

        return null;
    }

    private function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        if ($digits === '') {
            return '';
        }

        try {
            $normalized = UserPhoneAuthTable::normalizePhoneNumber('+' . $digits);
        } catch (\Throwable) {
            return '';
        }

        return is_string($normalized) ? $normalized : '';
    }

    /**
     * @return array{ok: true, userId: int|null}|array{ok: false, error: string}
     */
    private function resolveUserId(string $normalized, bool $attach = true): array
    {
        $row = UserPhoneAuthTable::getList([
            'select' => ['USER_ID'],
            'filter' => ['=PHONE_NUMBER' => $normalized],
            'limit' => 1,
        ])->fetch();
        if (is_array($row) && (int) ($row['USER_ID'] ?? 0) > 0) {
            return ['ok' => true, 'userId' => (int) $row['USER_ID']];
        }

        $digits = $this->digits($normalized);
        $userIds = $this->findUserIdsByProfileDigits($digits);
        if (count($userIds) > 1) {
            return ['ok' => false, 'error' => 'Найдено несколько пользователей с этим номером'];
        }
        if (count($userIds) === 1) {
            if (!$attach) {
                return ['ok' => true, 'userId' => null];
            }
            if (!$this->ensurePhoneAuthRow($userIds[0], $normalized)) {
                return ['ok' => false, 'error' => 'Не удалось отправить код'];
            }

            return ['ok' => true, 'userId' => $userIds[0]];
        }

        return ['ok' => true, 'userId' => null];
    }

    /**
     * @return list<int>
     */
    private function findUserIdsByProfileDigits(string $digits): array
    {
        if ($digits === '') {
            return [];
        }

        $candidates = [$digits, '+' . $digits];
        $normalized = $this->normalize($digits);
        if ($normalized !== '') {
            $candidates[] = $normalized;
        }
        $candidates = array_values(array_unique($candidates));

        $filter = ['LOGIC' => 'OR'];
        foreach ($candidates as $candidate) {
            $filter[] = ['=PERSONAL_PHONE' => $candidate];
            $filter[] = ['=PERSONAL_MOBILE' => $candidate];
            $filter[] = ['=WORK_PHONE' => $candidate];
        }

        $matched = [];
        $rows = UserTable::getList([
            'filter' => $filter,
            'select' => ['ID', 'PERSONAL_PHONE', 'PERSONAL_MOBILE', 'WORK_PHONE'],
        ]);
        while ($row = $rows->fetch()) {
            $userId = (int) ($row['ID'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            foreach (['PERSONAL_PHONE', 'PERSONAL_MOBILE', 'WORK_PHONE'] as $field) {
                if ($this->digits((string) ($row[$field] ?? '')) === $digits) {
                    $matched[$userId] = true;
                }
            }
        }

        return array_keys($matched);
    }

    private function ensurePhoneAuthRow(int $userId, string $normalized): bool
    {
        if ($userId <= 0 || $normalized === '') {
            return false;
        }

        $existing = UserPhoneAuthTable::getRowById($userId);
        if (is_array($existing)) {
            $stored = (string) ($existing['PHONE_NUMBER'] ?? '');
            if ($stored === $normalized) {
                return true;
            }
            if ($stored === '' || $this->digits($stored) !== $this->digits($normalized)) {
                return false;
            }

            return UserPhoneAuthTable::update($userId, ['PHONE_NUMBER' => $normalized])->isSuccess();
        }

        $result = UserPhoneAuthTable::add([
            'USER_ID' => $userId,
            'PHONE_NUMBER' => $normalized,
        ]);

        return $result->isSuccess();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function register(string $normalized, array $payload): array
    {
        $fullName = $this->scalarString($payload['fullName'] ?? null);
        if ($fullName === '') {
            return [
                'dataRequired' => ['fullName'],
            ];
        }

        $email = $this->scalarString($payload['email'] ?? null);
        if ($email !== '' && !check_email($email)) {
            return $this->error('Некорректный email');
        }

        $birthday = $this->birthdayField($payload['birthday'] ?? null);
        if ($birthday === false) {
            return $this->error('Некорректная дата рождения');
        }

        $gender = $this->genderField($payload['gender'] ?? null);
        if ($gender === false) {
            return $this->error('Некорректный пол');
        }

        $digits = $this->digits($normalized);
        if (strlen($digits) < 9) {
            return $this->error('Некорректный номер телефона');
        }

        [$name, $lastName, $secondName] = $this->splitName($fullName);
        $password = CUser::GeneratePasswordByPolicy([]);
        $fields = [
            'LOGIN' => $this->uniqueLogin($digits),
            'NAME' => $name,
            'LAST_NAME' => $lastName,
            'SECOND_NAME' => $secondName,
            'PASSWORD' => $password,
            'CONFIRM_PASSWORD' => $password,
            'ACTIVE' => 'N',
            'LID' => Config::getSiteId(),
            'PHONE_NUMBER' => $normalized,
            'PERSONAL_PHONE' => $normalized,
        ];
        if ($email !== '') {
            $fields['EMAIL'] = $email;
        }
        if (is_string($birthday)) {
            $fields['PERSONAL_BIRTHDAY'] = $birthday;
        }
        if (is_string($gender)) {
            $fields['PERSONAL_GENDER'] = $gender;
        }

        $user = new CUser();
        $userId = (int) $user->Add($fields);
        if ($userId <= 0) {
            return $this->error($this->userError($user->LAST_ERROR, 'Не удалось зарегистрировать пользователя'));
        }

        if (!$this->ensurePhoneAuthRow($userId, $normalized)) {
            return $this->error('Не удалось отправить код');
        }

        $this->notifyRegistered($userId);

        return $this->sendCode($userId, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendCode(int $userId, bool $registration): array
    {
        $row = UserPhoneAuthTable::getRowById($userId);
        if (!is_array($row) || (string) ($row['PHONE_NUMBER'] ?? '') === '') {
            return $this->error('Не удалось отправить код');
        }

        $phoneNumber = (string) $row['PHONE_NUMBER'];
        $interval = (int) CUser::PHONE_CODE_RESEND_INTERVAL;
        if ($interval <= 0) {
            $interval = 60;
        }

        $generate = true;
        $elapsed = 0;
        if (($row['DATE_SENT'] ?? null) instanceof DateTime) {
            $elapsed = time() - $row['DATE_SENT']->getTimestamp();
            if ($elapsed >= 0 && $elapsed < $interval) {
                $generate = false;
            }
        }

        $attempts = (int) ($row['ATTEMPTS'] ?? 0);
        $timeout = $interval;
        if ($generate) {
            $generated = CUser::GeneratePhoneCode($userId);
            if (!is_array($generated) || count($generated) < 2) {
                return $this->error('Не удалось отправить код');
            }

            [$smsCode, $generatedPhone] = $generated;
            if (is_string($generatedPhone) && $generatedPhone !== '') {
                $phoneNumber = $generatedPhone;
            }

            $sms = new SmsEvent(
                $registration ? self::SMS_REGISTER : self::SMS_LOGIN,
                [
                    'USER_PHONE' => $phoneNumber,
                    'CODE' => (string) $smsCode,
                ]
            );
            $sms->setSite(Config::getSiteId());
            $smsResult = $sms->send(true);
            if (!$smsResult->isSuccess()) {
                try {
                    UserPhoneAuthTable::update($userId, ['DATE_SENT' => '']);
                } catch (\Throwable) {
                    // The next request must be able to send again. A stuck DATE_SENT is worse.
                }

                return $this->error('Не удалось отправить код');
            }

            $attempts = 0;
        } else {
            $timeout = max(1, $interval - max(0, $elapsed));
        }

        return [
            'otp' => [
                'timeout' => $timeout,
                'attemptsLeft' => max(0, self::MAX_ATTEMPTS - $attempts),
                'message' => 'На номер ' . $this->maskPhone($phoneNumber) . ' отправлено сообщение с кодом',
                'replyText' => self::REPLY_TEXT,
                'codeLength' => $this->codeLength(),
            ],
        ];
    }

    private function activate(int $userId): ?string
    {
        if ($this->isActive($userId) !== false) {
            return null;
        }

        $user = new CUser();
        if ($user->Update($userId, ['ACTIVE' => 'Y'])) {
            return null;
        }

        return $this->userError($user->LAST_ERROR, 'Не удалось подтвердить код');
    }

    private function isActive(int $userId): ?bool
    {
        $row = UserTable::getRow([
            'select' => ['ACTIVE'],
            'filter' => ['=ID' => $userId],
        ]);
        if (!is_array($row)) {
            return null;
        }

        return (string) ($row['ACTIVE'] ?? 'N') === 'Y';
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
            RequestLogger::error('otp-register-hook', [
                'userId' => $userId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyAuthorized(int $userId): void
    {
        $class = \Dnk\PhpInterface\UserAddEvents::class;
        if (!class_exists($class)) {
            return;
        }

        try {
            $fields = ['ID' => $userId];
            $class::onAfterUserAuthorize($fields);
        } catch (\Throwable $exception) {
            RequestLogger::error('otp-authorize-hook', [
                'userId' => $userId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(int $userId, string $normalized): array
    {
        $row = UserTable::getRow([
            'select' => [
                'ID',
                'NAME',
                'LAST_NAME',
                'SECOND_NAME',
                'EMAIL',
                'PERSONAL_GENDER',
                'PERSONAL_BIRTHDAY',
            ],
            'filter' => ['=ID' => $userId],
        ]);

        $user = ['id' => (string) $userId];
        if (!is_array($row)) {
            $phone = $this->digits($normalized);
            if ($phone !== '') {
                $user['phone'] = $phone;
            }

            return $user;
        }

        $name = trim(implode(' ', array_filter(
            [
                (string) ($row['LAST_NAME'] ?? ''),
                (string) ($row['NAME'] ?? ''),
                (string) ($row['SECOND_NAME'] ?? ''),
            ],
            static fn (string $part): bool => $part !== ''
        )));
        if ($name !== '') {
            $user['name'] = $name;
        }

        $phoneRow = UserPhoneAuthTable::getRowById($userId);
        $phone = $this->digits(is_array($phoneRow) ? (string) ($phoneRow['PHONE_NUMBER'] ?? '') : '');
        if ($phone === '') {
            $phone = $this->digits($normalized);
        }
        if ($phone !== '') {
            $user['phone'] = $phone;
        }

        $email = trim((string) ($row['EMAIL'] ?? ''));
        if ($email !== '' && !$this->isTechnicalEmail($email)) {
            $user['email'] = $email;
        }

        $gender = $this->genderLabel((string) ($row['PERSONAL_GENDER'] ?? ''));
        if ($gender !== null) {
            $user['gender'] = $gender;
        }

        $age = $this->age($row['PERSONAL_BIRTHDAY'] ?? null);
        if ($age !== null) {
            $user['age'] = $age;
        }

        $this->appendLoyalty($user, $userId);

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function appendLoyalty(array &$user, int $userId): void
    {
        $utils = \Dnk\PhpInterface\Utils::class;
        if (!class_exists($utils)) {
            return;
        }

        $user['bonuses'] = $this->number($utils::getAsproBonusBalance($userId));

        $level = $utils::getUserBonusClientLevelDisplayData($userId);
        $loyalty = [];
        $title = trim((string) ($level['name'] ?? ''));
        if ($title !== '') {
            $loyalty['currentLevelTitle'] = $title;
        }
        if (
            ($level['show_next_level'] ?? false) === true
            && isset($level['next_level_cost'])
            && is_numeric($level['next_level_cost'])
        ) {
            $loyalty['nextLevelDescription'] = 'Совершите покупку на '
                . $this->number((float) $level['next_level_cost'])
                . ' рублей до следующего уровня';
        }
        if ($loyalty !== []) {
            $user['loyaltyProgram'] = $loyalty;
        }
    }

    private function isTechnicalEmail(string $email): bool
    {
        $utils = \Dnk\PhpInterface\Utils::class;
        if (class_exists($utils)) {
            return $utils::isTechnicalBuyerEmail($email);
        }

        return preg_match('/^buyer[0-9]+/i', trim($email)) === 1;
    }

    private function genderLabel(string $gender): ?string
    {
        $gender = strtoupper(trim($gender));
        if ($gender === 'M') {
            return 'male';
        }
        if ($gender === 'F') {
            return 'female';
        }

        return null;
    }

    private function age(mixed $birthday): ?int
    {
        $parsed = $this->birthdayDate($birthday);
        if ($parsed === null) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        if ($parsed > $today) {
            return null;
        }

        return $parsed->diff($today)->y;
    }

    /**
     * @return string|false|null Culture date string, null when absent, false when invalid.
     */
    private function birthdayField(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }

        $parsed = $this->parseIsoDate(trim($value));
        if ($parsed === null || $parsed > new \DateTimeImmutable('today')) {
            return false;
        }

        return (new Date($parsed->format('Y-m-d'), 'Y-m-d'))->toString();
    }

    /**
     * @return string|false|null Bitrix M/F, null when absent, false when invalid.
     */
    private function genderField(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }

        $gender = strtolower(trim($value));
        if ($gender === 'm' || $gender === 'male') {
            return 'M';
        }
        if ($gender === 'f' || $gender === 'female') {
            return 'F';
        }

        return false;
    }

    private function birthdayDate(mixed $birthday): ?\DateTimeImmutable
    {
        if ($birthday instanceof Date) {
            return $this->parseIsoDate($birthday->format('Y-m-d'));
        }
        if ($birthday instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($birthday)->setTime(0, 0);
        }
        if (!is_string($birthday) || trim($birthday) === '') {
            return null;
        }

        $value = trim($birthday);
        $iso = $this->parseIsoDate($value);
        if ($iso !== null) {
            return $iso;
        }

        $site = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $site instanceof \DateTimeImmutable
            && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
        ) {
            return $site;
        }

        return null;
    }

    private function parseIsoDate(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof \DateTimeImmutable
            || $parsed->format('Y-m-d') !== $value
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            return null;
        }

        return $parsed;
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

    private function maskPhone(string $phone): string
    {
        $digits = $this->digits($phone);
        $length = strlen($digits);
        if (str_starts_with($digits, '375') && $length === 12) {
            return '+375 (' . substr($digits, 3, 2) . ') ***-**-' . substr($digits, -2);
        }
        if (str_starts_with($digits, '7') && $length === 11) {
            return '+7 (' . substr($digits, 1, 3) . ') ***-**-' . substr($digits, -2);
        }
        if ($length < 6) {
            return $phone;
        }

        return '+' . substr($digits, 0, 4) . str_repeat('*', $length - 6) . substr($digits, -2);
    }

    private function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private function codeLength(): int
    {
        return (new TotpAlgorithm())->getDigits();
    }

    private function number(float $value): int|float
    {
        $rounded = round($value, 2);
        if (abs($rounded - round($rounded)) < 0.001) {
            return (int) round($rounded);
        }

        return $rounded;
    }

    private function scalarString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }

    private function userError(mixed $lastError, string $fallback): string
    {
        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $lastError);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return $text !== '' ? $text : $fallback;
    }
}
