<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Loader;
use Bitrix\Sale\Delivery\CalculationResult;
use Bitrix\Sale\Delivery\Services\Manager as DeliveryManager;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;
use Bx\Imshop\Integration\Http\RequestException;

/**
 * Same calculation sequence as SaleOrderAjax::initDeliveryServices() and calculateDeliveries().
 * The component itself is not executed: it depends on the visitor session and fuser basket.
 */
final class DeliveryCalculator
{
    public function __construct(
        private readonly CalculationOrderFactory $orders = new CalculationOrderFactory(),
        private readonly ImshopDeliveryMapper $mapper = new ImshopDeliveryMapper(),
        private readonly LocationResolver $locations = new LocationResolver(),
    ) {
    }

    /**
     * bonusesSpent is accepted by the protocol and is not applied:
     * Aspro bonus write-off is tied to the checkout component session.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function calculate(array $payload): array
    {
        if (!Loader::includeModule('sale')) {
            throw new RequestException('Модули магазина недоступны', 503);
        }

        $userId = $this->orders->userId($payload);
        DiscountCouponsManager::reInit(
            DiscountCouponsManager::MODE_EXTERNAL,
            ['userId' => $userId],
            true
        );

        try {
            $coupon = $payload['promocode'] ?? null;
            if (is_string($coupon) && trim($coupon) !== '') {
                DiscountCouponsManager::add(trim($coupon));
            }

            $order = $this->orders->create($payload);
            $order->doFinalAction(true);

            return $this->quote($order, $payload);
        } finally {
            DiscountCouponsManager::clear(true);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function quote(Order $order, array $payload): array
    {
        $shipment = $this->currentShipment($order);
        if ($shipment === null) {
            throw new RequestException('Не удалось подготовить отгрузку для расчёта доставки', 500);
        }

        $services = DeliveryManager::getRestrictedObjectsList($shipment);
        $skipPickupLocations = ($payload['skipPickupLocations'] ?? false) === true;
        $city = $this->locations->cityName($payload);
        $deliveries = [];

        foreach ($services as $service) {
            $quoted = $this->quoteService($order, $service, $skipPickupLocations, $city);
            if ($quoted !== null) {
                $deliveries[] = $quoted;
            }
        }

        if ($deliveries === []) {
            return [
                'deliveries' => [],
                'message' => 'В выбранный регион доставка не осуществляется',
            ];
        }

        return ['deliveries' => $deliveries];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function quoteService(Order $order, \Bitrix\Sale\Delivery\Services\Base $service, bool $skipPickupLocations, string $city): ?array
    {
        $clone = $order->createClone();
        $shipment = $this->currentShipment($clone);
        if ($shipment === null) {
            return null;
        }

        $shipment->setField('CUSTOM_PRICE_DELIVERY', 'N');
        $storeIds = \Bitrix\Sale\Delivery\ExtraServices\Manager::getStoresList($service->getId());
        if (is_array($storeIds) && $storeIds !== []) {
            $firstStoreId = (int) reset($storeIds);
            if ($firstStoreId > 0) {
                $shipment->setStoreId($firstStoreId);
            }
        }

        $shipment->setDeliveryService($service);
        $deliveryResult = $clone->getShipmentCollection()->calculateDelivery();
        if (!$deliveryResult->isSuccess()) {
            return null;
        }

        $calculated = $deliveryResult->get('CALCULATED_DELIVERIES');
        if (!is_array($calculated) || $calculated === []) {
            return null;
        }

        $calculation = reset($calculated);
        if (!$calculation instanceof CalculationResult || !$calculation->isSuccess()) {
            return null;
        }

        $clone->doFinalAction(true);

        return $this->mapper->map($service, $calculation, $clone, $skipPickupLocations, $city);
    }

    private function currentShipment(Order $order): ?Shipment
    {
        foreach ($order->getShipmentCollection() as $shipment) {
            if (!$shipment->isSystem()) {
                return $shipment;
            }
        }

        return null;
    }
}
