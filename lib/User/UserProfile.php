<?php

namespace Bx\Imshop\Integration\User;

use Bitrix\Main\Security\Mfa\TotpAlgorithm;
use Bitrix\Main\Type\Date;
use Bitrix\Main\UserPhoneAuthTable;
use Bitrix\Main\UserTable;

/**
 * IMSHOP account object shared by login, profile read and profile edit.
 */
final class UserProfile
{
    public function __construct(
        private readonly PhoneUserFinder $phones = new PhoneUserFinder(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(int $userId, string $phoneFallback = ''): array
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
            $phone = $this->phones->digits($phoneFallback);
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
        $phone = $this->phones->digits(is_array($phoneRow) ? (string) ($phoneRow['PHONE_NUMBER'] ?? '') : '');
        if ($phone === '') {
            $phone = $this->phones->digits($phoneFallback);
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

        $birthday = $this->isoDate($row['PERSONAL_BIRTHDAY'] ?? null);
        if ($birthday !== null) {
            $user['birthday'] = $birthday;
        }

        $this->appendLoyalty($user, $userId);

        return $user;
    }

    public function maskPhone(string $phone): string
    {
        $digits = $this->phones->digits($phone);
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

    public function codeLength(): int
    {
        return (new TotpAlgorithm())->getDigits();
    }

    /**
     * Фамилия, имя, отчество. Одно слово остаётся именем.
     *
     * @return array{0: string, 1: string, 2: string} NAME, LAST_NAME, SECOND_NAME
     */
    public function splitName(string $fullName): array
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

    /**
     * @return string|false|null Culture date string, null when absent, false when invalid.
     */
    public function birthdayField(mixed $value): string|false|null
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
    public function genderField(mixed $value): string|false|null
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
        if ($parsed === null || $parsed > new \DateTimeImmutable('today')) {
            return null;
        }

        return $parsed->diff(new \DateTimeImmutable('today'))->y;
    }

    private function isoDate(mixed $birthday): ?string
    {
        $parsed = $this->birthdayDate($birthday);
        if ($parsed === null || $parsed > new \DateTimeImmutable('today')) {
            return null;
        }

        return $parsed->format('Y-m-d');
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

    private function number(float $value): int|float
    {
        $rounded = round($value, 2);
        if (abs($rounded - round($rounded)) < 0.001) {
            return (int) round($rounded);
        }

        return $rounded;
    }
}
