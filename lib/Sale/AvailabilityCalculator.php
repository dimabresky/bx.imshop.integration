<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Sale\Location\LocationTable;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\RequestException;

/**
 * IMSHOP availability: catalog stores and free stock for YML offer ids.
 *
 * Offer id in the DNK feed is the catalog element id.
 */
final class AvailabilityCalculator
{
    private const QUANTITY_EPSILON = 0.000001;

    private const LANGUAGE_ID = 'ru';

    /**
     * @param array<string, mixed> $payload
     * @return array{warehouses: list<array<string, mixed>>, availability: list<array<string, mixed>>}
     */
    public function calculate(array $payload): array
    {
        $requested = $this->requestedIds($payload);
        if ($requested === []) {
            return $this->emptyResponse();
        }

        if (!Loader::includeModule('catalog')) {
            throw new RequestException('Модули магазина недоступны', 503);
        }

        $items = $this->existingProducts($requested);
        if ($items === []) {
            return $this->emptyResponse();
        }

        $city = $this->city($payload);
        $stores = $this->stores($city);
        if ($stores === []) {
            return $this->emptyResponse();
        }

        $productIds = [];
        foreach ($items as $item) {
            $productIds[$item['productId']] = $item['productId'];
        }

        $stock = $this->stock(array_keys($stores), array_values($productIds));
        $availability = [];
        $usedStoreIds = [];

        foreach ($items as $item) {
            foreach ($stores as $storeId => $warehouse) {
                $quantity = $stock[$storeId][$item['productId']] ?? 0.0;
                if ($quantity <= self::QUANTITY_EPSILON) {
                    continue;
                }

                $availability[] = [
                    'id' => $item['id'],
                    'warehouseId' => (string) $storeId,
                    'quantity' => $this->quantity($quantity),
                ];
                $usedStoreIds[$storeId] = true;
            }
        }

        $warehouses = [];
        foreach ($stores as $storeId => $warehouse) {
            if (isset($usedStoreIds[$storeId])) {
                $warehouses[] = $warehouse;
            }
        }

        return $this->applyEvent($city, $warehouses, $availability);
    }

    /**
     * @param list<array<string, mixed>> $warehouses
     * @param list<array<string, mixed>> $availability
     * @return array{warehouses: list<array<string, mixed>>, availability: list<array<string, mixed>>}
     */
    private function applyEvent(string $city, array $warehouses, array $availability): array
    {
        $event = new Event(Config::MODULE_ID, 'onAvailabilityWarehousesBuild', [
            'CITY' => $city,
            'WAREHOUSES' => $warehouses,
            'AVAILABILITY' => $availability,
        ]);
        $event->send();

        foreach ($event->getResults() as $result) {
            if (!$result instanceof EventResult || $result->getType() !== EventResult::SUCCESS) {
                continue;
            }

            $parameters = $result->getParameters();
            if (
                !is_array($parameters)
                || !isset($parameters['WAREHOUSES'], $parameters['AVAILABILITY'])
                || !is_array($parameters['WAREHOUSES'])
                || !is_array($parameters['AVAILABILITY'])
            ) {
                continue;
            }

            $warehouses = $parameters['WAREHOUSES'];
            $availability = $parameters['AVAILABILITY'];
        }

        return $this->dropOrphanRows($warehouses, $availability);
    }

    /**
     * @param list<mixed> $warehouses
     * @param list<mixed> $availability
     * @return array{warehouses: list<array<string, mixed>>, availability: list<array<string, mixed>>}
     */
    private function dropOrphanRows(array $warehouses, array $availability): array
    {
        $keptWarehouses = [];
        $warehouseIds = [];
        foreach ($warehouses as $warehouse) {
            if (!is_array($warehouse)) {
                continue;
            }

            $warehouseId = trim((string) ($warehouse['warehouseId'] ?? ''));
            if ($warehouseId === '') {
                continue;
            }

            $warehouseIds[$warehouseId] = true;
            $keptWarehouses[] = $warehouse;
        }

        $keptAvailability = [];
        $usedWarehouseIds = [];
        foreach ($availability as $row) {
            if (!is_array($row)) {
                continue;
            }

            $warehouseId = trim((string) ($row['warehouseId'] ?? ''));
            if ($warehouseId === '' || !isset($warehouseIds[$warehouseId])) {
                continue;
            }

            $keptAvailability[] = $row;
            $usedWarehouseIds[$warehouseId] = true;
        }

        $keptWarehouses = array_values(array_filter(
            $keptWarehouses,
            static fn (array $warehouse): bool => isset($usedWarehouseIds[(string) ($warehouse['warehouseId'] ?? '')])
        ));

        return [
            'warehouses' => $keptWarehouses,
            'availability' => $keptAvailability,
        ];
    }

    /**
     * @return array{warehouses: list<empty>, availability: list<empty>}
     */
    private function emptyResponse(): array
    {
        return [
            'warehouses' => [],
            'availability' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{id: string, productId: int}>
     */
    private function requestedIds(array $payload): array
    {
        if (!array_key_exists('configurationIds', $payload)) {
            return [];
        }

        $raw = $payload['configurationIds'];
        if ($raw === []) {
            return [];
        }

        if (!is_array($raw)) {
            throw new RequestException('configurationIds должен быть массивом', 400);
        }

        $items = [];
        $seen = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $id = trim((string) $value);
            if (preg_match('/^\d+$/', $id) !== 1) {
                continue;
            }

            $productId = (int) $id;
            if ($productId <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $items[] = [
                'id' => $id,
                'productId' => $productId,
            ];
        }

        return $items;
    }

    /**
     * @param list<array{id: string, productId: int}> $requested
     * @return list<array{id: string, productId: int}>
     */
    private function existingProducts(array $requested): array
    {
        $productIds = [];
        foreach ($requested as $item) {
            $productIds[$item['productId']] = $item['productId'];
        }

        $rows = ProductTable::getList([
            'select' => ['ID'],
            'filter' => ['@ID' => array_values($productIds)],
        ]);

        $existing = [];
        while ($row = $rows->fetch()) {
            if (!is_array($row)) {
                continue;
            }

            $productId = (int) ($row['ID'] ?? 0);
            if ($productId > 0) {
                $existing[$productId] = true;
            }
        }

        $items = [];
        foreach ($requested as $item) {
            if (isset($existing[$item['productId']])) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function city(array $payload): string
    {
        return $this->plainText((new LocationResolver())->cityName($payload));
    }

    /**
     * Active site stores that are pickup points or selected online warehouses.
     * City filtering is left to onAvailabilityWarehousesBuild.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stores(string $city): array
    {
        $dbList = \CCatalogStore::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'ACTIVE' => 'Y',
                '+SITE_ID' => Config::getSiteId(),
            ],
            false,
            false,
            [
                'ID',
                'TITLE',
                'ADDRESS',
                'GPS_N',
                'GPS_S',
                'ISSUING_CENTER',
                'LOCATION_ID',
                'UF_METRO',
            ]
        );
        if (!is_object($dbList)) {
            return [];
        }

        $rows = [];
        $locationIds = [];
        while ($store = $dbList->Fetch()) {
            if (!is_array($store)) {
                continue;
            }

            $storeId = (int) ($store['ID'] ?? 0);
            if ($storeId <= 0) {
                continue;
            }

            $rows[] = $store;
            $locationId = (int) ($store['LOCATION_ID'] ?? 0);
            if ($locationId > 0) {
                $locationIds[$locationId] = $locationId;
            }
        }

        $locationNames = $this->locationNames(array_values($locationIds));
        $onlineStoreIds = array_fill_keys(Config::onlineStoreIds(), true);
        $stores = [];
        foreach ($rows as $store) {
            $warehouse = $this->warehouse($store, $city, $locationNames, $onlineStoreIds);
            if ($warehouse === null) {
                continue;
            }

            $stores[(int) $store['ID']] = $warehouse;
        }

        return $stores;
    }

    /**
     * @param list<int> $locationIds
     * @return array<int, string>
     */
    private function locationNames(array $locationIds): array
    {
        if ($locationIds === [] || !Loader::includeModule('sale')) {
            return [];
        }

        $rows = LocationTable::getList([
            'select' => [
                'ID',
                'LOCATION_NAME' => 'NAME.NAME',
            ],
            'filter' => [
                '@ID' => $locationIds,
                '=NAME.LANGUAGE_ID' => self::LANGUAGE_ID,
            ],
        ]);

        $names = [];
        while ($row = $rows->fetch()) {
            if (!is_array($row)) {
                continue;
            }

            $locationId = (int) ($row['ID'] ?? 0);
            $name = $this->plainText((string) ($row['LOCATION_NAME'] ?? ''));
            if ($locationId > 0 && $name !== '') {
                $names[$locationId] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $store
     * @param array<int, string> $locationNames
     * @param array<int, true> $onlineStoreIds
     * @return array<string, mixed>|null
     */
    private function warehouse(array $store, string $city, array $locationNames, array $onlineStoreIds): ?array
    {
        $name = $this->plainText((string) ($store['TITLE'] ?? ''));
        if ($name === '') {
            return null;
        }

        $storeId = (int) $store['ID'];
        $isShop = $this->isYes($store['ISSUING_CENTER'] ?? null);
        $isOnline = isset($onlineStoreIds[$storeId]);
        if (!$isShop && !$isOnline) {
            return null;
        }

        $storeCity = $locationNames[(int) ($store['LOCATION_ID'] ?? 0)] ?? '';
        $warehouseCity = $isOnline ? $city : $storeCity;
        $warehouse = [
            'warehouseId' => (string) $storeId,
            'name' => $name,
            'address' => $this->plainText((string) ($store['ADDRESS'] ?? '')),
            'online' => $isOnline,
            'public' => $isShop && (!$isOnline || $this->sameCity($storeCity, $city)),
        ];
        if ($warehouseCity !== '') {
            $warehouse['city'] = $warehouseCity;
        }

        $latitude = $this->coordinate($store['GPS_N'] ?? null);
        $longitude = $this->coordinate($store['GPS_S'] ?? null);
        if (
            $latitude !== null
            && $longitude !== null
            && $latitude >= -90
            && $latitude <= 90
            && ($latitude !== 0.0 || $longitude !== 0.0)
        ) {
            $warehouse['lat'] = $latitude;
            $warehouse['lon'] = $longitude;
        }

        $subway = $this->subway($store['UF_METRO'] ?? null);
        if ($subway !== '') {
            $warehouse['subway'] = $subway;
        }

        return $warehouse;
    }

    /**
     * @param list<int> $storeIds
     * @param list<int> $productIds
     * @return array<int, array<int, float>>
     */
    private function stock(array $storeIds, array $productIds): array
    {
        if ($storeIds === [] || $productIds === []) {
            return [];
        }

        $rows = StoreProductTable::getList([
            'filter' => [
                '@STORE_ID' => $storeIds,
                '@PRODUCT_ID' => $productIds,
            ],
            'select' => ['STORE_ID', 'PRODUCT_ID', 'AMOUNT', 'QUANTITY_RESERVED'],
        ]);

        $available = [];
        while ($row = $rows->fetch()) {
            if (!is_array($row)) {
                continue;
            }

            $storeId = (int) ($row['STORE_ID'] ?? 0);
            $productId = (int) ($row['PRODUCT_ID'] ?? 0);
            if ($storeId <= 0 || $productId <= 0) {
                continue;
            }

            $free = (float) ($row['AMOUNT'] ?? 0) - (float) ($row['QUANTITY_RESERVED'] ?? 0);
            if ($free < 0) {
                $free = 0.0;
            }

            $available[$storeId][$productId] = ($available[$storeId][$productId] ?? 0.0) + $free;
        }

        return $available;
    }

    private function quantity(float $quantity): int|float
    {
        $rounded = round($quantity, 3);
        if (abs($rounded - round($rounded)) < self::QUANTITY_EPSILON) {
            return (int) round($rounded);
        }

        return $rounded;
    }

    private function sameCity(string $storeCity, string $city): bool
    {
        return $city !== '' && $storeCity !== '' && mb_strtolower($storeCity) === mb_strtolower($city);
    }

    private function isYes(mixed $value): bool
    {
        return $value === 'Y' || $value === true || $value === 1 || $value === '1';
    }

    private function coordinate(mixed $value): ?float
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $text = str_replace(',', '.', trim((string) $value));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        $number = (float) $text;
        if ($number < -180 || $number > 180) {
            return null;
        }

        return $number;
    }

    private function subway(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $text = trim($value);
        if ($text === '' || preg_match('/^[aOsibd]:/', $text) === 1) {
            return '';
        }

        return $this->plainText($text);
    }

    private function plainText(string $value): string
    {
        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
