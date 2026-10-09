<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\ElementTable;
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

/**
 * Basket total after Bitrix catalog prices and sale basket rules.
 * The order stays in memory. IMSHOP promo groups and gift cards are not applied.
 * bonuses.canSpend is the Aspro spend limit. The balance is not written off.
 */
final class BasketCalculator
{
    private const UNAVAILABLE = 'Товар недоступен для заказа';

    public function __construct(
        private readonly CalculationOrderFactory $orders = new CalculationOrderFactory(),
        private readonly CatalogItemResolver $items = new CatalogItemResolver(),
    ) {
    }

    /**
     * promoGroup, giftCards and promocodes are accepted by the protocol and are not applied.
     * authorizedBonuses is the amount the shopper chose to spend. totalPrice stays without that spend.
     * Marketing actions are a later iteration.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function calculate(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new RequestException('Поле items должно быть массивом', 400);
        }

        if (!Loader::includeModule('sale') || !Loader::includeModule('catalog')) {
            throw new RequestException('Модули магазина недоступны', 503);
        }

        $userId = $this->orders->userId($payload);
        DiscountCouponsManager::reInit(
            DiscountCouponsManager::MODE_EXTERNAL,
            ['userId' => $userId],
            true
        );

        try {
            $coupon = $this->coupon($payload);
            if ($coupon !== '') {
                DiscountCouponsManager::add($coupon);
            }

            $totals = $this->totals($payload, $items, $userId);

            return $this->withCoupon($totals, $coupon);
        } finally {
            DiscountCouponsManager::clear(true);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<mixed> $items
     * @return array{totalPrice: float, discount: float, items: list<array<string, mixed>>, bonuses?: array{canSpend: float}}
     */
    private function totals(array $payload, array $items, int $userId): array
    {
        $groups = $this->userGroups($userId);
        $slots = [];
        $orderItems = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $this->items->catalogProductId($item);
            $quantity = $this->quantity($item);
            if ($productId <= 0 || $quantity <= 0) {
                $slots[] = $this->unavailable($item, max(0, $productId), $quantity);
                continue;
            }

            if (($item['selected'] ?? true) === false) {
                $slots[] = $this->isPurchasable($productId)
                    ? $this->deferred($item, $productId, $quantity, $groups)
                    : $this->unavailable($item, $productId, $quantity);
                continue;
            }

            $lineKey = 'imshop-' . $index;
            $copy = $item;
            $copy['lineKey'] = $lineKey;
            $orderItems[] = $copy;
            $slots[] = [
                'lineKey' => $lineKey,
                'item' => $item,
                'productId' => $productId,
            ];
        }

        $calculated = $orderItems === []
            ? ['lines' => [], 'extras' => [], 'canSpend' => null]
            : $this->pricedLines($payload, $orderItems);
        $responseItems = [];
        $totalPrice = 0.0;
        $discount = 0.0;

        foreach ($slots as $slot) {
            if (isset($slot['lineKey']) && is_string($slot['lineKey'])) {
                $line = $calculated['lines'][$slot['lineKey']] ?? $this->unavailable(
                    is_array($slot['item'] ?? null) ? $slot['item'] : [],
                    (int) ($slot['productId'] ?? 0),
                    $this->quantity(is_array($slot['item'] ?? null) ? $slot['item'] : [])
                );
            } else {
                $line = $slot;
            }

            $this->addToTotals($line, $totalPrice, $discount);
            $responseItems[] = $line;
        }

        foreach ($calculated['extras'] as $extra) {
            $this->addToTotals($extra, $totalPrice, $discount);
            $responseItems[] = $extra;
        }

        $result = [
            'totalPrice' => PriceMaths::roundPrecision($totalPrice),
            'discount' => PriceMaths::roundPrecision($discount),
            'items' => $responseItems,
        ];
        if (is_float($calculated['canSpend']) || is_int($calculated['canSpend'])) {
            $result['bonuses'] = [
                'canSpend' => PriceMaths::roundPrecision((float) $calculated['canSpend']),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $orderItems
     * @return array{lines: array<string, array<string, mixed>>, extras: list<array<string, mixed>>, canSpend: float|null}
     */
    private function pricedLines(array $payload, array $orderItems): array
    {
        $orderPayload = $payload;
        $orderPayload['items'] = $orderItems;
        if (($payload['purchaseForLegalEntityMode'] ?? false) === true) {
            $orderPayload['legalEntityMode'] = true;
        }

        $order = $this->orders->create($orderPayload, false, false);
        $this->restoreLineKeys($order, $orderItems);
        $order->doFinalAction(true);
        $this->applyDelivery($order, $payload);
        $this->applyPayment($order, $payload);
        $order->doFinalAction(true);

        $quote = $this->bonusQuote($order, $payload);
        $lines = [];
        $extras = [];
        $basket = $order->getBasket();
        if ($basket === null) {
            return [
                'lines' => $lines,
                'extras' => $extras,
                'canSpend' => is_array($quote) ? (float) $quote['canSpend'] : null,
            ];
        }

        /** @var BasketItem $basketItem */
        foreach ($basket as $basketItem) {
            if ($basketItem->isBundleChild()) {
                continue;
            }

            $lineKey = trim((string) $basketItem->getField('XML_ID'));
            if (str_starts_with($lineKey, 'imshop-')) {
                if (!isset($lines[$lineKey])) {
                    $lines[$lineKey] = $this->withLineBonus(
                        $this->fromBasketItem($basketItem, $orderItems, $lineKey),
                        $quote,
                        (int) $basketItem->getId()
                    );
                }
                continue;
            }

            if (!$basketItem->canBuy() || $basketItem->getQuantity() <= 0) {
                continue;
            }

            $extras[] = $this->withLineBonus(
                $this->fromBasketItem($basketItem, [], ''),
                $quote,
                (int) $basketItem->getId()
            );
        }

        return [
            'lines' => $lines,
            'extras' => $extras,
            'canSpend' => is_array($quote) ? (float) $quote['canSpend'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function addToTotals(array $line, float &$totalPrice, float &$discount): void
    {
        if (($line['selected'] ?? false) !== true || ($line['canBePurchased'] ?? false) !== true) {
            return;
        }

        $totalPrice += (float) ($line['subtotal'] ?? 0);
        $discount += (float) ($line['discount'] ?? 0);
    }

    /**
     * Refresh keeps basket rows but may replace XML_ID. The row order still matches the request.
     *
     * @param list<array<string, mixed>> $orderItems
     */
    private function restoreLineKeys(Order $order, array $orderItems): void
    {
        $basket = $order->getBasket();
        if ($basket === null) {
            return;
        }

        $basketItems = [];
        foreach ($basket as $basketItem) {
            $basketItems[] = $basketItem;
        }

        if (count($basketItems) !== count($orderItems)) {
            return;
        }

        foreach ($orderItems as $index => $source) {
            $lineKey = $source['lineKey'] ?? null;
            if (!is_string($lineKey) || $lineKey === '') {
                continue;
            }

            $basketItems[$index]->setField('XML_ID', $lineKey);
        }
    }

    /**
     * @param list<array<string, mixed>> $orderItems
     * @return array<string, mixed>
     */
    private function fromBasketItem(BasketItem $basketItem, array $orderItems, string $lineKey): array
    {
        $source = $this->sourceItem($orderItems, $lineKey);
        $productId = (int) $basketItem->getProductId();
        $quantity = (float) $basketItem->getQuantity();
        $name = trim((string) $basketItem->getField('NAME'));
        if ($name === '') {
            $name = $this->productName($productId, $this->requestName($source));
        }

        $row = [
            'id' => (string) $productId,
            'name' => $name,
            'price' => PriceMaths::roundPrecision($basketItem->getBasePrice()),
            'discount' => PriceMaths::roundPrecision($basketItem->getDiscountPrice() * $quantity),
            'quantity' => $this->quantityValue($quantity),
            'subtotal' => PriceMaths::roundPrecision($basketItem->getFinalPrice()),
        ];
        $this->copyOptional($source, $row);

        if ($basketItem->canBuy() && $quantity > 0) {
            $row['canBePurchased'] = true;
            $row['selected'] = true;

            return $row;
        }

        $row['error'] = self::UNAVAILABLE;
        $row['canBePurchased'] = false;
        $row['selected'] = false;

        return $row;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<int> $groups
     * @return array<string, mixed>
     */
    private function deferred(array $item, int $productId, float $quantity, array $groups): array
    {
        $optimal = \CCatalogProduct::GetOptimalPrice(
            $productId,
            $quantity,
            $groups,
            'N',
            [],
            Config::getSiteId()
        );
        $resultPrice = is_array($optimal) ? ($optimal['RESULT_PRICE'] ?? null) : null;
        if (!is_array($resultPrice)) {
            return $this->unavailable($item, $productId, $quantity);
        }

        $row = [
            'id' => (string) $productId,
            'name' => $this->productName($productId, $this->requestName($item)),
            'price' => PriceMaths::roundPrecision((float) ($resultPrice['BASE_PRICE'] ?? 0)),
            'discount' => PriceMaths::roundPrecision((float) ($resultPrice['DISCOUNT'] ?? 0) * $quantity),
            'quantity' => $this->quantityValue($quantity),
            'subtotal' => PriceMaths::roundPrecision((float) ($resultPrice['DISCOUNT_PRICE'] ?? 0) * $quantity),
            'canBePurchased' => true,
            'selected' => false,
        ];
        $this->copyOptional($item, $row);

        return $row;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function unavailable(array $item, int $productId, float $quantity): array
    {
        $row = [
            'id' => $this->responseId($item, $productId),
            'name' => $this->productName($productId, $this->requestName($item)),
            'price' => 0,
            'discount' => 0,
            'quantity' => $this->quantityValue($quantity > 0 ? $quantity : 0),
            'subtotal' => 0,
            'error' => self::UNAVAILABLE,
            'canBePurchased' => false,
            'selected' => false,
        ];
        $this->copyOptional($item, $row);

        return $row;
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function withCoupon(array $totals, string $coupon): array
    {
        $applied = null;
        $error = '';
        if ($coupon !== '') {
            $data = $this->couponData($coupon);
            $status = is_array($data) ? (int) ($data['STATUS'] ?? 0) : 0;
            if ($status === DiscountCouponsManager::STATUS_APPLYED) {
                $applied = (string) ($data['COUPON'] ?? $coupon);
            } else {
                $error = $this->couponError($data);
            }
        }

        $response = [
            'totalPrice' => $totals['totalPrice'],
            'appliedPromocode' => $applied,
            'discount' => $totals['discount'],
            'items' => $totals['items'],
        ];
        if ($error !== '') {
            $response['promocodeErrorMessage'] = $error;
        }
        if (isset($totals['bonuses']) && is_array($totals['bonuses'])) {
            $response['bonuses'] = $totals['bonuses'];
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function couponData(string $coupon): ?array
    {
        $coupons = DiscountCouponsManager::get(true, [], true, true);
        if (!is_array($coupons)) {
            return null;
        }

        if (isset($coupons[$coupon]) && is_array($coupons[$coupon])) {
            return $coupons[$coupon];
        }

        foreach ($coupons as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (strcasecmp((string) ($row['COUPON'] ?? ''), $coupon) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function couponError(?array $data): string
    {
        if ($data === null) {
            return 'Промокод не найден';
        }

        $text = $data['CHECK_CODE_TEXT'] ?? $data['STATUS_TEXT'] ?? '';
        if (is_array($text)) {
            $parts = [];
            foreach ($text as $part) {
                if (is_string($part) && trim($part) !== '') {
                    $parts[] = trim($part);
                }
            }
            $text = implode('. ', $parts);
        }

        if (!is_string($text) || trim($text) === '') {
            return 'Промокод не применён';
        }

        return trim($text);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyDelivery(Order $order, array $payload): void
    {
        $deliveryId = $this->positiveId($payload['deliveryId'] ?? null);
        if ($deliveryId <= 0) {
            return;
        }

        $shipment = $this->currentShipment($order);
        if ($shipment === null) {
            return;
        }

        try {
            $service = DeliveryManager::getObjectById($deliveryId);
        } catch (\Throwable) {
            return;
        }

        if ($service === null) {
            return;
        }

        $shipment->setField('CUSTOM_PRICE_DELIVERY', 'N');
        $storeId = $this->positiveId($payload['deliveryPickupId'] ?? null);
        if ($storeId <= 0) {
            $storeId = $this->positiveId($payload['pickupLocationId'] ?? null);
        }
        if ($storeId > 0) {
            $shipment->setStoreId($storeId);
        }

        $shipment->setDeliveryService($service);
        try {
            $order->getShipmentCollection()->calculateDelivery();
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayment(Order $order, array $payload): void
    {
        $paySystemId = $this->positiveId($payload['paymentId'] ?? null);
        if ($paySystemId <= 0) {
            return;
        }

        $service = PaySystemManager::getObjectById($paySystemId);
        if ($service === null) {
            return;
        }

        $payment = $order->getPaymentCollection()->createItem($service);
        $sum = $order->getPrice();
        $result = $payment->setFields([
            'SUM' => $sum > 0 ? $sum : 0,
            'CURRENCY' => $order->getCurrency(),
        ]);
        if (!$result->isSuccess()) {
            $payment->delete();
        }
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

    /**
     * @param array<string, mixed> $payload
     * @return array{canSpend: float, lines: array<int, float>}|null
     */
    private function bonusQuote(Order $order, array $payload): ?array
    {
        $service = \Dnk\PhpInterface\BasketBonusService::class;
        if (!class_exists($service)) {
            return null;
        }

        $quote = $service::quote($order, $this->authorizedBonuses($payload));

        return is_array($quote) ? $quote : null;
    }

    /**
     * @param array<string, mixed> $line
     * @param array{canSpend: float, lines: array<int, float>}|null $quote
     * @return array<string, mixed>
     */
    private function withLineBonus(array $line, ?array $quote, int $basketItemId): array
    {
        if (
            $quote === null
            || $basketItemId <= 0
            || ($line['selected'] ?? false) !== true
            || ($line['canBePurchased'] ?? false) !== true
            || !isset($quote['lines'][$basketItemId])
        ) {
            return $line;
        }

        $canSpend = (float) $quote['lines'][$basketItemId];
        if ($canSpend <= 0) {
            return $line;
        }

        $line['bonuses'] = [
            'canSpend' => PriceMaths::roundPrecision($canSpend),
        ];

        return $line;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function authorizedBonuses(array $payload): float
    {
        $raw = $payload['authorizedBonuses'] ?? 0;
        if (!is_numeric($raw)) {
            return 0.0;
        }

        $amount = (float) $raw;

        return $amount > 0 ? $amount : 0.0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function coupon(array $payload): string
    {
        $coupon = $payload['promocode'] ?? null;
        if (!is_string($coupon)) {
            return '';
        }

        return trim($coupon);
    }

    /**
     * @return list<int>
     */
    private function userGroups(int $userId): array
    {
        if ($userId <= 0) {
            return [2];
        }

        $groups = \CUser::GetUserGroup($userId);
        if (!is_array($groups) || $groups === []) {
            return [2];
        }

        $ids = [];
        foreach ($groups as $group) {
            if (is_numeric($group) && (int) $group > 0) {
                $ids[] = (int) $group;
            }
        }

        return $ids !== [] ? $ids : [2];
    }

    private function isPurchasable(int $productId): bool
    {
        $product = ProductTable::getByPrimary($productId, ['select' => ['ID', 'AVAILABLE']])->fetch();
        if (!is_array($product) || ($product['AVAILABLE'] ?? 'N') !== 'Y') {
            return false;
        }

        if (!Loader::includeModule('iblock')) {
            return true;
        }

        $element = ElementTable::getByPrimary($productId, ['select' => ['ID', 'ACTIVE']])->fetch();

        return is_array($element) && ($element['ACTIVE'] ?? 'N') === 'Y';
    }

    private function productName(int $productId, string $fallback): string
    {
        if ($productId <= 0 || !Loader::includeModule('iblock')) {
            return $fallback;
        }

        $element = ElementTable::getByPrimary($productId, ['select' => ['NAME']])->fetch();
        $name = is_array($element) ? trim((string) ($element['NAME'] ?? '')) : '';

        return $name !== '' ? $name : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $orderItems
     * @return array<string, mixed>
     */
    private function sourceItem(array $orderItems, string $lineKey): array
    {
        foreach ($orderItems as $item) {
            if (($item['lineKey'] ?? null) === $lineKey) {
                return $item;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function requestName(array $item): string
    {
        $name = $item['name'] ?? null;

        return is_string($name) ? trim($name) : '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function responseId(array $item, int $productId): string
    {
        if ($productId > 0) {
            return (string) $productId;
        }

        foreach (['configurationId', 'privateId', 'id'] as $key) {
            $raw = $item[$key] ?? null;
            if (is_scalar($raw) && trim((string) $raw) !== '') {
                return trim((string) $raw);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function quantity(array $item): float
    {
        $raw = $item['quantity'] ?? 0;
        if (!is_numeric($raw)) {
            return 0.0;
        }

        return (float) $raw;
    }

    private function quantityValue(float $quantity): int|float
    {
        if (abs($quantity - round($quantity)) < 1e-6) {
            return (int) round($quantity);
        }

        return $quantity;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function copyOptional(array $source, array &$target): void
    {
        foreach (['itemKitId', 'fractionOptionId'] as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $target[$key] = $value;
            }
        }
    }
}
