<?php

namespace Bx\Imshop\Integration\User;

use Bitrix\Main\UserPhoneAuthTable;
use Bitrix\Main\UserTable;

/**
 * Looks up a Bitrix user by phone: auth table first, then profile phone fields.
 */
final class PhoneUserFinder
{
    public function normalize(string $raw): string
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

    public function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    /**
     * @return array{kind: 'auth'|'profile'|'missing'|'ambiguous', userId: int}
     */
    public function find(string $normalized): array
    {
        if ($normalized === '') {
            return ['kind' => 'missing', 'userId' => 0];
        }

        $row = UserPhoneAuthTable::getList([
            'select' => ['USER_ID'],
            'filter' => ['=PHONE_NUMBER' => $normalized],
            'limit' => 1,
        ])->fetch();
        if (is_array($row) && (int) ($row['USER_ID'] ?? 0) > 0) {
            return ['kind' => 'auth', 'userId' => (int) $row['USER_ID']];
        }

        $userIds = $this->findUserIdsByProfileDigits($this->digits($normalized));
        if (count($userIds) > 1) {
            return ['kind' => 'ambiguous', 'userId' => 0];
        }
        if (count($userIds) === 1) {
            return ['kind' => 'profile', 'userId' => $userIds[0]];
        }

        return ['kind' => 'missing', 'userId' => 0];
    }

    public function ensurePhoneAuthRow(int $userId, string $normalized): bool
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

        $ids = [];
        foreach (array_keys($matched) as $userId) {
            $ids[] = (int) $userId;
        }

        return $ids;
    }
}
