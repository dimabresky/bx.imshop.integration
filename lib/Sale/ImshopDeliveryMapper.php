<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
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
    private const PICKUP_RADIUS_KM = 10.0;

    private const PICKUP_NEAREST_LIMIT = 10;

    /**
     * @return array<string, mixed>|null
     */
    public function map(
        Base $service,
        CalculationResult $calculation,
        Order $order,
        bool $skipPickupLocations,
        string $city,
        ?float $latitude,
        ?float $longitude,
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
        $isPickup = $storeIds !== [] || Config::isPickupDelivery($service->getId());
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
            $locations = $this->collectFromEvent(
                $service,
                $order,
                $locations,
                $city,
                $price,
                $min ?? 0,
                $timeLabel,
                $latitude,
                $longitude,
            );
            $locations = $this->normalizeLocations($locations, $price, $min ?? 0, $timeLabel, $city);
            $locations = $this->nearest($locations, $latitude, $longitude);
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
     * @param list<array<string, mixed>> $locations
     * @return list<array<string, mixed>>
     */
    private function collectFromEvent(
        Base $service,
        Order $order,
        array $locations,
        string $city,
        float $price,
        int $min,
        string $timeLabel,
        ?float $latitude,
        ?float $longitude,
    ): array {
        $event = new Event(Config::MODULE_ID, 'onPickupLocationsBuild', [
            'DELIVERY_ID' => $service->getId(),
            'DELIVERY_CLASS' => get_class($service),
            'DELIVERY_NAME' => $service->isProfile() ? $service->getNameWithParent() : $service->getName(),
            'LOCATIONS' => $locations,
            'CITY' => $city,
            'LOCATION_CODE' => $this->locationCode($order),
            'PRICE' => $price,
            'MIN' => $min,
            'TIME_LABEL' => $timeLabel,
            'LAT' => $latitude,
            'LON' => $longitude,
            'ORDER' => $order,
        ]);
        $event->send();

        foreach ($event->getResults() as $result) {
            if (!$result instanceof EventResult || $result->getType() !== EventResult::SUCCESS) {
                continue;
            }

            $parameters = $result->getParameters();
            if (!is_array($parameters) || !array_key_exists('LOCATIONS', $parameters) || !is_array($parameters['LOCATIONS'])) {
                continue;
            }

            $locations = $parameters['LOCATIONS'];
        }

        return $locations;
    }

    /**
     * @param mixed $locations
     * @return list<array<string, mixed>>
     */
    private function normalizeLocations(mixed $locations, float $price, int $min, string $timeLabel, string $city): array
    {
        if (!is_array($locations)) {
            return [];
        }

        $normalized = [];
        foreach ($locations as $location) {
            if (!is_array($location)) {
                continue;
            }

            $id = trim((string) ($location['id'] ?? ''));
            $title = $this->plainText((string) ($location['title'] ?? ''));
            $address = $this->plainText((string) ($location['address'] ?? ''));
            $pointCity = $this->plainText((string) ($location['city'] ?? ''));
            if ($pointCity === '') {
                $pointCity = $city;
            }

            $lat = trim((string) ($location['lat'] ?? ''));
            $lon = trim((string) ($location['lon'] ?? ''));
            if ($id === '' || $title === '' || $address === '' || $pointCity === '' || !$this->isCoordinatePair($lat, $lon)) {
                continue;
            }

            $item = [
                'id' => $id,
                'title' => $title,
                'address' => $address,
                'city' => $pointCity,
                'lat' => $lat,
                'lon' => $lon,
                'price' => isset($location['price']) && is_numeric($location['price']) ? (float) $location['price'] : $price,
                'min' => isset($location['min']) && is_numeric($location['min']) ? (int) $location['min'] : $min,
            ];

            $time = $this->plainText((string) ($location['time'] ?? ''));
            if ($time !== '') {
                $item['time'] = $time;
            }

            $label = $this->plainText((string) ($location['timeLabel'] ?? ''));
            if ($label === '') {
                $label = $timeLabel;
            }
            if ($label !== '') {
                $item['timeLabel'] = $label;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $locations
     * @return list<array<string, mixed>>
     */
    private function nearest(array $locations, ?float $latitude, ?float $longitude): array
    {
        if ($latitude === null || $longitude === null) {
            return $locations;
        }

        $ranked = [];
        foreach ($locations as $location) {
            $distance = $this->distanceKm(
                $latitude,
                $longitude,
                (float) $location['lat'],
                (float) $location['lon'],
            );
            if ($distance <= self::PICKUP_RADIUS_KM) {
                $ranked[] = ['distance' => $distance, 'location' => $location];
            }
        }

        usort(
            $ranked,
            static fn (array $left, array $right): int => $left['distance'] <=> $right['distance']
        );

        $nearest = [];
        foreach (array_slice($ranked, 0, self::PICKUP_NEAREST_LIMIT) as $row) {
            $nearest[] = $row['location'];
        }

        return $nearest;
    }

    private function distanceKm(float $fromLat, float $fromLon, float $toLat, float $toLon): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($toLat - $fromLat);
        $lonDelta = deg2rad($toLon - $fromLon);
        $haversine = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * (2 * atan2(sqrt($haversine), sqrt(1 - $haversine)));
    }

    private function locationCode(Order $order): string
    {
        $location = $order->getPropertyCollection()->getDeliveryLocation();
        if ($location === null) {
            return '';
        }

        return trim((string) $location->getValue());
    }

    /**
     * @param array<string, mixed> $store
     */
    private function hasCoordinates(array $store): bool
    {
        return $this->isCoordinatePair(
            trim((string) ($store['GPS_N'] ?? '')),
            trim((string) ($store['GPS_S'] ?? '')),
        );
    }

    private function isCoordinatePair(string $lat, string $lon): bool
    {
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
