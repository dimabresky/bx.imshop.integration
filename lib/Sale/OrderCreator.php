<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Delivery\Services\Manager as DeliveryManager;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem\Manager as PaySystemManager;
use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Shipment;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\RequestException;
use Bx\Imshop\Integration\Http\RequestLogger;

/**
 * Places IMSHOP orders through Sale. Request line prices are ignored.
 * authorizedBonuses and paymentProcessed are accepted and not applied.
 * Order::save() is allowed only here.
 */
final class OrderCreator
{
    private const SUCCESS_MESSAGE = 'Ваш заказ принят.';

    public function __construct(
        private readonly CalculationOrderFactory $orders = new CalculationOrderFactory(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function place(array $payload): array
    {
        if (!Loader::includeModule('sale') || !Loader::includeModule('catalog')) {
            throw new RequestException('Модули магазина недоступны', 503);
        }

        $incoming = $payload['orders'] ?? null;
        if (!is_array($incoming) || $incoming === []) {
            throw new RequestException('В запросе нет заказов', 400);
        }

        $placed = [];
        foreach ($incoming as $source) {
            if (!is_array($source)) {
                $placed[] = $this->failure('', 'order_invalid', 'Некорректный заказ');
                continue;
            }

            $placed[] = $this->placeOne($source);
        }

        if (count($placed) === 1 && ($placed[0]['success'] ?? false) === true) {
            $message = (string) ($placed[0]['message'] ?? self::SUCCESS_MESSAGE);
            unset($placed[0]['message']);

            return [
                'message' => $message,
                'orders' => $placed,
            ];
        }

        return ['orders' => $placed];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function placeOne(array $source): array
    {
        $uuid = $this->uuid($source);
        if ($uuid === '') {
            return $this->failure('', 'order_invalid', 'Не передан идентификатор заказа');
        }

        $connection = Application::getConnection();
        $lockName = $this->lockName($uuid);
        if (!$connection->lock($lockName, 15)) {
            return $this->failure($uuid, 'order_rejected', 'Не удалось оформить заказ');
        }

        try {
            $existing = $this->findByUuid($uuid);
            if ($existing !== null) {
                return $this->success($existing, $source);
            }

            return $this->create($source, $uuid);
        } catch (RequestException $exception) {
            return $this->failure($uuid, 'order_rejected', $exception->getMessage());
        } catch (\Throwable $exception) {
            RequestLogger::error('order', [
                'uuid' => $uuid,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->failure($uuid, 'order_rejected', 'Не удалось оформить заказ');
        } finally {
            $connection->unlock($lockName);
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function create(array $source, string $uuid): array
    {
        $userId = $this->orders->userId($source);
        DiscountCouponsManager::reInit(
            DiscountCouponsManager::MODE_EXTERNAL,
            ['userId' => $userId],
            true
        );

        try {
            $coupon = $source['promocode'] ?? null;
            if (is_string($coupon) && trim($coupon) !== '') {
                DiscountCouponsManager::add(trim($coupon));
            }

            $order = $this->orders->create($this->factoryPayload($source));
            $this->assignUser($order, $userId);
            $this->fillBuyer($order, $source);
            $this->fillComment($order, $source);
            $order->setField('XML_ID', $uuid);
            $order->doFinalAction(true);
            $this->applyDelivery($order, $source);
            $order->doFinalAction(true);
            $this->applyPayment($order, $source);

            $save = $order->save();
            if (!$save->isSuccess()) {
                return $this->failure($uuid, 'save_failed', $this->saveMessage($save->getErrorMessages()));
            }

            $savedId = (int) $order->getId();
            $canonical = $this->findByUuid($uuid);
            if ($canonical !== null && (int) $canonical->getId() !== $savedId) {
                Order::delete($savedId);

                return $this->success($canonical, $source);
            }

            $saved = Order::load($savedId);

            return $this->success($saved instanceof Order ? $saved : $order, $source);
        } finally {
            DiscountCouponsManager::clear(true);
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function factoryPayload(array $source): array
    {
        $payload = $source;
        $legalEntity = $source['legalEntity'] ?? null;
        if (is_array($legalEntity) && $legalEntity !== []) {
            $payload['legalEntityMode'] = true;
        }

        $address = trim((string) ($source['address'] ?? ''));
        $addressData = $source['addressData'] ?? null;
        if (!is_array($addressData)) {
            $addressData = [];
        }

        $value = trim((string) ($addressData['value'] ?? ''));
        if ($value === '' && $address !== '') {
            $addressData['value'] = $address;
            $payload['addressData'] = $addressData;
        }

        return $payload;
    }

    private function assignUser(Order $order, int $userId): void
    {
        if ($userId > 0) {
            return;
        }

        $anonymous = (int) \CSaleUser::GetAnonymousUserID();
        if ($anonymous <= 0) {
            throw new RequestException('Не удалось определить покупателя', 500);
        }

        $result = $order->setField('USER_ID', $anonymous);
        if (!$result->isSuccess()) {
            throw new RequestException('Не удалось определить покупателя', 500);
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function fillBuyer(Order $order, array $source): void
    {
        $properties = $order->getPropertyCollection();
        $this->setProperty($properties->getPayerName(), $this->text($source, 'name'));
        $this->setProperty($properties->getProfileName(), $this->text($source, 'name'));
        $this->setProperty($properties->getPhone(), $this->text($source, 'phone'));
        $this->setProperty($properties->getUserEmail(), $this->text($source, 'email'));
    }

    private function setProperty(?\Bitrix\Sale\EntityPropertyValue $property, string $value): void
    {
        if ($property === null || $value === '') {
            return;
        }

        $property->setValue($value);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function fillComment(Order $order, array $source): void
    {
        $parts = [];
        $comment = $this->text($source, 'deliveryComment');
        if ($comment !== '') {
            $parts[] = $comment;
        }

        $recipient = $source['anotherRecipientData'] ?? null;
        if (is_array($recipient)) {
            $name = trim((string) ($recipient['name'] ?? ''));
            $phone = trim((string) ($recipient['phone'] ?? ''));
            $line = trim($name . ($phone !== '' ? ', ' . $phone : ''));
            if ($line !== '') {
                $parts[] = 'Получатель: ' . $line;
            }
        }

        if ($parts === []) {
            return;
        }

        $order->setField('USER_DESCRIPTION', implode("\n", $parts));
    }

    /**
     * @param array<string, mixed> $source
     */
    private function applyDelivery(Order $order, array $source): void
    {
        $deliveryId = $this->positiveId($source['delivery'] ?? null);
        if ($deliveryId <= 0) {
            throw new RequestException('Не указан способ доставки', 200);
        }

        $shipment = $this->currentShipment($order);
        if ($shipment === null) {
            throw new RequestException('Не удалось подготовить отгрузку', 500);
        }

        try {
            $service = DeliveryManager::getObjectById($deliveryId);
        } catch (\Bitrix\Main\SystemException) {
            $service = null;
        }

        if ($service === null) {
            throw new RequestException('Не удалось применить способ доставки', 200);
        }

        $shipment->setField('CUSTOM_PRICE_DELIVERY', 'N');
        $storeId = $this->positiveId($source['pickupLocationId'] ?? null);
        if ($storeId > 0) {
            $shipment->setStoreId($storeId);
        }

        $shipment->setDeliveryService($service);
        $calculated = $order->getShipmentCollection()->calculateDelivery();
        if (!$calculated->isSuccess()) {
            throw new RequestException('Не удалось применить способ доставки', 200);
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function applyPayment(Order $order, array $source): void
    {
        $paySystemId = $this->positiveId($source['payment'] ?? null);
        if ($paySystemId <= 0) {
            throw new RequestException('Не указан способ оплаты', 200);
        }

        $service = PaySystemManager::getObjectById($paySystemId);
        if ($service === null) {
            throw new RequestException('Не удалось применить способ оплаты', 200);
        }

        $payment = $order->getPaymentCollection()->createItem($service);
        $sum = $order->getPrice();
        $result = $payment->setFields([
            'SUM' => $sum > 0 ? $sum : 0,
            'CURRENCY' => $order->getCurrency(),
        ]);
        if (!$result->isSuccess()) {
            throw new RequestException('Не удалось применить способ оплаты', 200);
        }
    }

    private function findByUuid(string $uuid): ?Order
    {
        $row = Order::getList([
            'select' => ['ID'],
            'filter' => [
                '=XML_ID' => $uuid,
                '=LID' => Config::getSiteId(),
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => 1,
        ])->fetch();

        if (!is_array($row)) {
            return null;
        }

        $id = (int) ($row['ID'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $order = Order::load($id);

        return $order instanceof Order ? $order : null;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function success(Order $order, array $source): array
    {
        $currency = (string) $order->getCurrency();
        $response = [
            'success' => true,
            'id' => (string) $order->getId(),
            'publicId' => (string) $order->getField('ACCOUNT_NUMBER'),
            'uuid' => (string) $order->getField('XML_ID'),
            'price' => (float) PriceMaths::roundByFormatCurrency($order->getPrice(), $currency),
            'deliveryPrice' => (float) PriceMaths::roundByFormatCurrency($order->getDeliveryPrice(), $currency),
            'items' => $this->items($order, $source),
            'message' => self::SUCCESS_MESSAGE,
        ];

        return $response;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<array<string, mixed>>
     */
    private function items(Order $order, array $source): array
    {
        $currency = (string) $order->getCurrency();
        $sources = $this->sourcesByProduct($source);
        $items = [];
        $basket = $order->getBasket();
        if ($basket === null) {
            return [];
        }

        /** @var BasketItem $basketItem */
        foreach ($basket as $basketItem) {
            if (!$basketItem->canBuy() || $basketItem->getQuantity() <= 0) {
                continue;
            }

            $productId = (int) $basketItem->getProductId();
            $origin = $this->shiftSource($sources, $productId);
            $quantity = (float) $basketItem->getQuantity();
            $unit = (float) PriceMaths::roundByFormatCurrency($basketItem->getBasePrice(), $currency);
            $subtotal = (float) PriceMaths::roundByFormatCurrency($basketItem->getFinalPrice(), $currency);
            $gross = (float) PriceMaths::roundByFormatCurrency($unit * $quantity, $currency);
            $discount = $gross - $subtotal;
            if ($discount < 0) {
                $discount = 0.0;
            }

            $item = [
                'name' => (string) $basketItem->getField('NAME'),
                'id' => (string) ($origin['id'] ?? $productId),
                'privateId' => (string) ($origin['privateId'] ?? $productId),
                'configurationId' => (string) ($origin['configurationId'] ?? $productId),
                'price' => $unit,
                'quantity' => $quantity,
                'discount' => $discount,
                'subtotal' => $subtotal,
            ];

            $kitId = trim((string) ($origin['itemKitId'] ?? ''));
            if ($kitId !== '') {
                $item['itemKitId'] = $kitId;
            }

            $fraction = trim((string) ($origin['fractionOptionId'] ?? ''));
            if ($fraction !== '') {
                $item['fractionOptionId'] = $fraction;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, list<array<string, mixed>>>
     */
    private function sourcesByProduct(array $source): array
    {
        $items = $source['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $grouped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $this->productId($item);
            if ($productId <= 0) {
                continue;
            }

            $grouped[$productId][] = $item;
        }

        return $grouped;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $sources
     * @return array<string, mixed>
     */
    private function shiftSource(array &$sources, int $productId): array
    {
        if (!isset($sources[$productId]) || $sources[$productId] === []) {
            return [];
        }

        $item = array_shift($sources[$productId]);

        return is_array($item) ? $item : [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function productId(array $item): int
    {
        foreach (['configurationId', 'privateId', 'id'] as $key) {
            $raw = $item[$key] ?? null;
            if (is_numeric($raw) && (int) $raw > 0) {
                return (int) $raw;
            }
        }

        return 0;
    }

    /**
     * @param list<string> $messages
     */
    private function saveMessage(array $messages): string
    {
        $text = trim(implode(' ', $messages));

        return $text !== '' ? $text : 'Не удалось сохранить заказ';
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $uuid, string $code, string $message): array
    {
        $failure = [
            'success' => false,
            'errorCode' => $code,
            'errorMessage' => $message,
        ];
        if ($uuid !== '') {
            $failure['uuid'] = $uuid;
        }

        return $failure;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function uuid(array $source): string
    {
        return $this->text($source, 'uuid');
    }

    private function lockName(string $uuid): string
    {
        return 'bx_imshop_order_' . substr(hash('sha256', Config::getSiteId() . ':' . $uuid), 0, 40);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function text(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
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

    private function positiveId(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : 0;
        }

        if (!is_string($raw) && !is_numeric($raw)) {
            return 0;
        }

        $value = trim((string) $raw);
        if (str_starts_with($value, 'webhook/')) {
            $value = substr($value, strlen('webhook/'));
        }

        if ($value === '' || !ctype_digit($value)) {
            return 0;
        }

        $id = (int) $value;

        return $id > 0 ? $id : 0;
    }
}
