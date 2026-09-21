<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Loader;
use Bitrix\Sale\Delivery\CalculationResult;
use Bitrix\Sale\Delivery\ExtraServices\Manager as ExtraServicesManager;
use Bitrix\Sale\Delivery\Services\Base;
use Bitrix\Sale\Order;
use Bx\Imshop\Integration\Config;

/**
 * Bitrix delivery service to the IMSHOP deliveries item.
 * Pickup points follow SaleOrderAjax::obtainDelivery().
 */
final class ImshopDeliveryMapper
{
    /**
     * @return array<string, mixed>|null
     */
    public function map(
        Base $service,
        CalculationResult $calculation,
        Order $order,
        bool $skipPickupLocations,
        string $city,
    ): ?array {
        if (!$calculation->isSuccess()) {
            return null;
        }

        $title = $service->isProfile() ? $service->getNameWithParent() : $service->getName();
        $title = $this->plainText($title);
        if ($title === '') {
            return null;
        }

        $storeIds = $this->storeIds($service->getId());
        $isPickup = $storeIds !== [];
        $price = $this->customerPrice($calculation, $order);
        $min = $this->toDays($calculation->getPeriodFrom(), (string) $calculation->getPeriodType());

        $delivery = [
            'id' => (string) $service->getId(),
            'title' => $title,
            'type' => $isPickup ? 'pickup' : 'delivery',
            'hasPickupLocations' => $isPickup,
            'price' => $price,
            'min' => $min ?? 0,
        ];

        $description = $this->plainText($service->getDescription());
        if ($description !== '') {
            $delivery['description'] = $description;
        }

        $timeLabel = $this->plainText($calculation->getPeriodDescription());
        if ($timeLabel !== '') {
            $delivery['timeLabel'] = $timeLabel;
        }

        $max = $this->toDays($calculation->getPeriodTo(), (string) $calculation->getPeriodType());
        if ($max !== null) {
            $delivery['max'] = $max;
        }

        if ($isPickup && !$skipPickupLocations) {
            $locations = $this->locations($storeIds, $price, $min ?? 0, $timeLabel, $city);
            if ($locations === []) {
                return null;
            }

            $delivery['locations'] = $locations;
        }

        return $delivery;
    }

    /**
     * @return list<int>
     */
    private function storeIds(int $deliveryId): array
    {
        $stores = ExtraServicesManager::getStoresList($deliveryId);
        if (!is_array($stores)) {
            return [];
        }

        $ids = [];
        foreach ($stores as $storeId) {
            if (is_numeric($storeId) && (int) $storeId > 0) {
                $ids[] = (int) $storeId;
            }
        }

        return $ids;
    }

    private function customerPrice(CalculationResult $calculation, Order $order): float
    {
        $currency = (string) $order->getCurrency();
        $basePrice = \Bitrix\Sale\PriceMaths::roundByFormatCurrency($calculation->getPrice(), $currency);
        $orderPrice = \Bitrix\Sale\PriceMaths::roundByFormatCurrency($order->getDeliveryPrice(), $currency);
        if ($orderPrice >= 0 && $orderPrice != $basePrice) {
            return (float) $orderPrice;
        }

        return (float) $basePrice;
    }

    private function toDays(mixed $period, string $periodType): ?int
    {
        if ($period === null || $period === '') {
            return null;
        }

        $value = (int) $period;
        if ($periodType === CalculationResult::PERIOD_TYPE_HOUR) {
            return $value < 24 ? 0 : intdiv($value, 24);
        }

        if ($periodType === CalculationResult::PERIOD_TYPE_MIN) {
            return $value < 24 * 60 ? 0 : (int) ceil($value / (24 * 60));
        }

        if ($periodType === CalculationResult::PERIOD_TYPE_MONTH) {
            return $value * 30;
        }

        return $value;
    }

    /**
     * @param list<int> $storeIds
     * @return list<array<string, mixed>>
     */
    private function locations(array $storeIds, float $price, int $min, string $timeLabel, string $city): array
    {
        if ($storeIds === [] || !Loader::includeModule('catalog')) {
            return [];
        }

        $locations = [];
        $dbList = \CCatalogStore::GetList(
            ['SORT' => 'DESC', 'ID' => 'DESC'],
            [
                'ACTIVE' => 'Y',
                'ID' => $storeIds,
                'ISSUING_CENTER' => 'Y',
                '+SITE_ID' => Config::getSiteId(),
            ],
            false,
            false,
            ['ID', 'TITLE', 'ADDRESS', 'SCHEDULE', 'GPS_N', 'GPS_S']
        );

        while ($store = $dbList->Fetch()) {
            if (!is_array($store) || !$this->hasCoordinates($store)) {
                continue;
            }

            $storeTitle = $this->plainText((string) ($store['TITLE'] ?? ''));
            $address = $this->plainText((string) ($store['ADDRESS'] ?? ''));
            if ($storeTitle === '' || $address === '' || $city === '') {
                continue;
            }

            $location = [
                'id' => (string) $store['ID'],
                'title' => $storeTitle,
                'address' => $address,
                'city' => $city,
                'lat' => (string) $store['GPS_N'],
                'lon' => (string) $store['GPS_S'],
                'price' => $price,
                'min' => $min,
            ];

            $schedule = $this->plainText((string) ($store['SCHEDULE'] ?? ''));
            if ($schedule !== '') {
                $location['time'] = $schedule;
            }
            if ($timeLabel !== '') {
                $location['timeLabel'] = $timeLabel;
            }

            $locations[] = $location;
        }

        return $locations;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function hasCoordinates(array $store): bool
    {
        $lat = trim((string) ($store['GPS_N'] ?? ''));
        $lon = trim((string) ($store['GPS_S'] ?? ''));
        if ($lat === '' || $lon === '' || !is_numeric($lat) || !is_numeric($lon)) {
            return false;
        }

        return (float) $lat !== 0.0 || (float) $lon !== 0.0;
    }

    private function plainText(string $value): string
    {
        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
