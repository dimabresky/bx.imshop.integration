<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Catalog\Product\Basket as CatalogBasket;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Basket\RefreshFactory;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\RequestException;

/**
 * Virtual order for delivery calculation. Order::save() is intentionally absent.
 */
final class CalculationOrderFactory
{
    public function __construct(
        private readonly CatalogItemResolver $items = new CatalogItemResolver(),
        private readonly LocationResolver $locations = new LocationResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): Order
    {
        if (!Loader::includeModule('sale') || !Loader::includeModule('catalog')) {
            throw new RequestException('Модули магазина недоступны', 503);
        }

        $lines = $this->items->resolve($payload);
        if ($lines === []) {
            throw new RequestException('Не удалось собрать корзину для расчёта доставки', 200);
        }

        $locationCode = $this->locations->resolveCode($payload);
        if ($locationCode === null) {
            throw new RequestException('В выбранный регион доставка не осуществляется', 200);
        }

        $legalEntity = ($payload['legalEntityMode'] ?? false) === true;
        $personTypeId = Config::resolvePersonTypeId($legalEntity);
        if ($personTypeId <= 0) {
            throw new RequestException('Не настроен тип плательщика', 503);
        }

        $siteId = Config::getSiteId();
        $userId = $this->userId($payload);
        $order = Order::create($siteId, $userId > 0 ? $userId : null);
        $personTypeResult = $order->setPersonTypeId($personTypeId);
        if (!$personTypeResult->isSuccess()) {
            throw new RequestException('Не удалось подготовить заказ для расчёта доставки', 500);
        }

        $basket = Basket::create($siteId);
        $order->setBasket($basket);
        $provider = CatalogBasket::getDefaultProviderName();
        if ($provider === '') {
            $provider = \Bitrix\Catalog\Product\CatalogProvider::class;
        }

        foreach ($lines as $line) {
            $basketItem = $basket->createItem('catalog', $line['productId']);
            $fieldsResult = $basketItem->setFields([
                'QUANTITY' => $line['quantity'],
                'CURRENCY' => $order->getCurrency(),
                'LID' => $siteId,
                'PRODUCT_PROVIDER_CLASS' => $provider,
            ]);
            if (!$fieldsResult->isSuccess()) {
                $basketItem->delete();
            }
        }

        $basket->refresh(RefreshFactory::create(RefreshFactory::TYPE_FULL));
        if (!$this->hasBuyableItems($basket)) {
            throw new RequestException('Не удалось собрать корзину для расчёта доставки', 200);
        }

        $this->fillAddress($order, $payload, $locationCode);
        $this->createShipment($order);

        return $order;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function userId(array $payload): int
    {
        $external = $payload['externalUserId'] ?? null;
        if (!is_numeric($external) || (int) $external <= 0) {
            return 0;
        }

        if (!Loader::includeModule('main')) {
            return 0;
        }

        $user = UserTable::getByPrimary((int) $external, ['select' => ['ID']])->fetch();

        return is_array($user) ? (int) $user['ID'] : 0;
    }

    private function fillAddress(Order $order, array $payload, string $locationCode): void
    {
        $properties = $order->getPropertyCollection();
        $location = $properties->getDeliveryLocation();
        if ($location === null) {
            throw new RequestException('Для типа плательщика не настроено местоположение доставки', 503);
        }

        $locationResult = $location->setValue($locationCode);
        if (!$locationResult->isSuccess()) {
            throw new RequestException('Не удалось установить местоположение доставки', 500);
        }

        $zip = $this->locations->zip($payload);
        $zipProperty = $properties->getDeliveryLocationZip();
        if ($zip !== '' && $zipProperty !== null) {
            $zipProperty->setValue($zip);
        }

        $address = $this->locations->addressValue($payload);
        $addressProperty = $properties->getAddress();
        if ($address !== '' && $addressProperty !== null) {
            $addressProperty->setValue($address);
        }
    }

    private function createShipment(Order $order): void
    {
        $shipmentCollection = $order->getShipmentCollection();
        $shipment = $shipmentCollection->createItem();
        $shipment->setField('CURRENCY', $order->getCurrency());
        $shipmentItemCollection = $shipment->getShipmentItemCollection();

        /** @var BasketItem $basketItem */
        foreach ($order->getBasket() as $basketItem) {
            if (!$basketItem->canBuy() || $basketItem->getQuantity() <= 0) {
                continue;
            }

            $shipmentItem = $shipmentItemCollection->createItem($basketItem);
            if ($shipmentItem === null) {
                continue;
            }

            $quantityResult = $shipmentItem->setQuantity($basketItem->getQuantity());
            if (!$quantityResult->isSuccess()) {
                throw new RequestException('Не удалось подготовить отгрузку для расчёта доставки', 500);
            }
        }
    }

    private function hasBuyableItems(Basket $basket): bool
    {
        foreach ($basket as $basketItem) {
            if ($basketItem->canBuy() && $basketItem->getQuantity() > 0) {
                return true;
            }
        }

        return false;
    }
}
