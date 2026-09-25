<?php

namespace Bx\Imshop\Integration;

use Bitrix\Main\Config\Option;
use Bitrix\Main\SiteTable;
use Bitrix\Sale\PersonType;

/**
 * Module options. Secrets stay in the Bitrix option storage of the stand.
 */
final class Config
{
    public const MODULE_ID = 'bx.imshop.integration';

    public static function isEnabled(): bool
    {
        return Option::get(self::MODULE_ID, 'enabled', 'Y') === 'Y';
    }

    public static function isLoggingEnabled(): bool
    {
        return Option::get(self::MODULE_ID, 'logging', 'N') === 'Y';
    }

    public static function isSecretLoggingEnabled(): bool
    {
        return Option::get(self::MODULE_ID, 'logging_secrets', 'N') === 'Y';
    }

    public static function getApiKey(): string
    {
        return trim((string) Option::get(self::MODULE_ID, 'api_key', ''));
    }

    public static function getSiteId(): string
    {
        $siteId = trim((string) Option::get(self::MODULE_ID, 'site_id', ''));
        if ($siteId !== '') {
            return $siteId;
        }

        $site = SiteTable::getList([
            'select' => ['LID'],
            'filter' => ['=ACTIVE' => 'Y', '=DEF' => 'Y'],
            'limit' => 1,
        ])->fetch();

        $lid = is_array($site) ? trim((string) ($site['LID'] ?? '')) : '';

        return $lid !== '' ? $lid : 's1';
    }

    public static function resolvePersonTypeId(bool $legalEntity): int
    {
        $siteId = self::getSiteId();
        $configured = $legalEntity
            ? (int) Option::get(self::MODULE_ID, 'person_type_legal_id', '0')
            : (int) Option::get(self::MODULE_ID, 'person_type_id', '0');

        if ($configured <= 0 && $legalEntity) {
            $configured = (int) Option::get(self::MODULE_ID, 'person_type_id', '0');
        }

        $personTypes = PersonType::load($siteId);
        if (!is_array($personTypes) || $personTypes === []) {
            return max(0, $configured);
        }

        if ($configured > 0 && isset($personTypes[$configured])) {
            return $configured;
        }

        $firstId = (int) array_key_first($personTypes);

        return $firstId > 0 ? $firstId : max(0, $configured);
    }
}
