<?php

namespace Bx\Imshop\Integration;

use Bitrix\Main\Config\Option;
use Bitrix\Main\SiteTable;
use Bitrix\Sale\PersonType;
use Bx\Imshop\Integration\Sale\OrderStatusMapTable;

/**
 * Module options. Secrets stay in the Bitrix option storage of the stand.
 */
final class Config
{
    public const MODULE_ID = 'bx.imshop.integration';

    public const ORDER_STATUS_MESSAGE_MAX = 500;

    /**
     * IMSHOP order status codes. Admin labels live in the options lang file.
     *
     * @var list<string>
     */
    public const ORDER_STATUS_CODES = [
        'placed',
        'processing',
        'ready_to_dispatch',
        'dispatched',
        'ready_for_pickup',
        'delivered',
        'closed',
        'canceled',
        'done',
    ];

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

    public static function storesIblockId(): int
    {
        return max(0, (int) Option::get(self::MODULE_ID, 'stores_iblock_id', '0'));
    }

    public static function statusSyncUrl(): string
    {
        return trim((string) Option::get(self::MODULE_ID, 'status_sync_url', ''));
    }

    public static function statusSyncToken(): string
    {
        return trim((string) Option::get(self::MODULE_ID, 'status_sync_token', ''));
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

    /**
     * Delivery service ids marked as pickup points in the module settings.
     *
     * @return list<int>
     */
    public static function pickupDeliveryIds(): array
    {
        $raw = (string) Option::get(self::MODULE_ID, 'pickup_delivery_ids', '');
        $ids = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            if (is_numeric($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function isPickupDelivery(int $deliveryId): bool
    {
        return $deliveryId > 0 && in_array($deliveryId, self::pickupDeliveryIds(), true);
    }

    /**
     * Keep Bitrix stores that can fulfill every buyable basket line.
     * Pickup points added by onPickupLocationsBuild are not affected.
     */
    public static function requireStoresWithAllProducts(): bool
    {
        return Option::get(self::MODULE_ID, 'stores_require_all_products', 'N') === 'Y';
    }

    /**
     * IMSHOP status code to a Bitrix order status and the message stored with that change.
     * Rows without a Bitrix status are omitted. An empty table yields an empty map.
     *
     * @return array<string, array{status_id: string, message: string}>
     */
    public static function orderStatusMap(): array
    {
        OrderStatusMapTable::ensure();

        $loaded = [];
        $rows = OrderStatusMapTable::getList([
            'select' => ['IMSHOP_CODE', 'STATUS_ID', 'MESSAGE'],
        ]);
        while ($row = $rows->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['IMSHOP_CODE'] ?? ''));
            if ($code === '') {
                continue;
            }
            $loaded[$code] = $row;
        }

        $map = [];
        foreach (self::ORDER_STATUS_CODES as $code) {
            $row = $loaded[$code] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $statusId = trim((string) ($row['STATUS_ID'] ?? ''));
            if ($statusId === '') {
                continue;
            }
            $message = trim((string) ($row['MESSAGE'] ?? ''));
            if (mb_strlen($message) > self::ORDER_STATUS_MESSAGE_MAX) {
                $message = mb_substr($message, 0, self::ORDER_STATUS_MESSAGE_MAX);
            }
            $map[$code] = [
                'status_id' => $statusId,
                'message' => $message,
            ];
        }

        return $map;
    }
}
