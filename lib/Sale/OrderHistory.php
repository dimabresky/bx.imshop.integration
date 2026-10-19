<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Internals\StatusLangTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Shipment;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\RequestException;
use Bx\Imshop\Integration\User\UserAccount;

/**
 * Page of a buyer's orders for IMSHOP.
 * shortcut: usedBonuses is always 0, upgrade when the order stores spent bonuses.
 * shortcut: inProgress treats only status F and CANCELED as finished, upgrade when the status map marks a final status.
 */
final class OrderHistory
{
    private const PAGE_SIZE = 20;

    private const LANGUAGE_ID = 'ru';

    private const FINISHED_STATUS_ID = 'F';

    public function __construct(
        private readonly UserAccount $accounts = new UserAccount(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function page(array $payload): array
    {
        if (!Loader::includeModule('sale')) {
            throw new RequestException('Модули магазина недоступны', 503);
        }

        $resolved = $this->accounts->resolve($payload);
        if (!($resolved['ok'] ?? false)) {
            return ['orders' => []];
        }

        $pageRaw = $payload['page'] ?? 1;
        $page = is_numeric($pageRaw) ? (int) $pageRaw : 1;
        if ($page < 1) {
            $page = 1;
        }

        $rows = Order::getList([
            'select' => ['ID'],
            'filter' => [
                '=USER_ID' => $resolved['userId'],
                '=LID' => Config::getSiteId(),
            ],
            'order' => ['DATE_INSERT' => 'DESC', 'ID' => 'DESC'],
            'limit' => self::PAGE_SIZE + 1,
            'offset' => ($page - 1) * self::PAGE_SIZE,
        ]);

        $ids = [];
        while ($row = $rows->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['ID'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $hasNext = count($ids) > self::PAGE_SIZE;
        $ids = array_slice($ids, 0, self::PAGE_SIZE);
        $loaded = [];
        foreach ($ids as $id) {
            $order = Order::load($id);
            if ($order instanceof Order) {
                $loaded[] = $order;
            }
        }

        $result = ['orders' => $this->orders($loaded)];
        if ($hasNext) {
            $result['nextPage'] = $page + 1;
        }

        return $result;
    }

    /**
     * @param list<Order> $loaded
     * @return list<array<string, mixed>>
     */
    private function orders(array $loaded): array
    {
        $statusIds = [];
        $productIds = [];
        foreach ($loaded as $order) {
            $statusIds[] = (string) $order->getField('STATUS_ID');
            $basket = $order->getBasket();
            if ($basket === null) {
                continue;
            }
            foreach ($basket as $item) {
                if ($item instanceof BasketItem && (float) $item->getQuantity() > 0) {
                    $productIds[] = (int) $item->getProductId();
                }
            }
        }

        $statusNames = $this->statusNames($statusIds);
        $pictures = $this->pictures($productIds);
        $orders = [];
        foreach ($loaded as $order) {
            $orders[] = $this->order($order, $statusNames, $pictures);
        }

        return $orders;
    }

    /**
     * @param array<string, string> $statusNames
     * @param array<int, string> $pictures
     * @return array<string, mixed>
     */
    private function order(Order $order, array $statusNames, array $pictures): array
    {
        $currency = (string) $order->getCurrency();
        $statusId = (string) $order->getField('STATUS_ID');
        $status = $statusNames[$statusId] ?? '';
        if ($status === '') {
            $status = $statusId;
        }

        $inProgress = (string) $order->getField('CANCELED') !== 'Y'
            && $statusId !== self::FINISHED_STATUS_ID;
        $items = $this->items($order, $currency, $pictures);
        $itemsPrice = 0.0;
        foreach ($items as $item) {
            $itemsPrice += (float) $item['price'] * (float) $item['quantity'];
        }

        $publicId = trim((string) $order->getField('ACCOUNT_NUMBER'));
        $row = [
            'id' => (string) $order->getId(),
            'publicId' => $publicId !== '' ? $publicId : (string) $order->getId(),
            'createdOn' => $this->timestamp($order->getDateInsert()),
            'updatedOn' => $this->timestamp($order->getField('DATE_UPDATE')),
            'status' => $status,
            'price' => $this->money($order->getPrice(), $currency),
            'deliveryPrice' => $this->money($order->getDeliveryPrice(), $currency),
            'itemsPrice' => $this->money($itemsPrice, $currency),
            'appliedDiscount' => $this->money(max(0, (float) $order->getDiscountPrice()), $currency),
            'usedBonuses' => 0,
            'inProgress' => $inProgress,
            'cancellable' => $inProgress,
            'items' => $items,
            'publicDeliveryDetails' => $this->delivery($order),
            'publicPaymentDetails' => $this->payment($order),
        ];

        return $row;
    }

    /**
     * @param array<int, string> $pictures
     * @return list<array<string, mixed>>
     */
    private function items(Order $order, string $currency, array $pictures): array
    {
        $basket = $order->getBasket();
        if ($basket === null) {
            return [];
        }

        $items = [];
        foreach ($basket as $item) {
            if (!$item instanceof BasketItem) {
                continue;
            }
            $quantity = (float) $item->getQuantity();
            if ($quantity <= 0) {
                continue;
            }

            $productId = (int) $item->getProductId();
            $unit = $this->money($item->getBasePrice(), $currency);
            $subtotal = $this->money($item->getFinalPrice(), $currency);
            $gross = $this->money($unit * $quantity, $currency);
            $discount = $gross - $subtotal;
            if ($discount < 0) {
                $discount = 0.0;
            }

            $items[] = [
                'privateId' => (string) $productId,
                'configurationId' => (string) $productId,
                'name' => trim((string) $item->getField('NAME')),
                'image' => $pictures[$productId] ?? '',
                'price' => $unit,
                'quantity' => $this->quantity($quantity),
                'discount' => $discount,
                'subtotal' => $subtotal,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function delivery(Order $order): array
    {
        $properties = $order->getPropertyCollection();
        $details = [
            'title' => '',
            'buyerName' => $this->propertyText($properties->getPayerName()),
            'buyerPhone' => $this->propertyText($properties->getPhone()),
            'buyerEmail' => $this->propertyText($properties->getUserEmail()),
        ];

        foreach ($order->getShipmentCollection() as $shipment) {
            if (!$shipment instanceof Shipment || $shipment->isSystem()) {
                continue;
            }
            $details['title'] = trim((string) $shipment->getDeliveryName());
            break;
        }

        $address = $this->propertyText($properties->getAddress());
        if ($address !== '') {
            $details['details'] = $address;
        }

        return $details;
    }

    /**
     * @return array{paid: bool, paymentComment: string}
     */
    private function payment(Order $order): array
    {
        $comment = '';
        foreach ($order->getPaymentCollection() as $payment) {
            if (!$payment instanceof Payment || $payment->isInner()) {
                continue;
            }
            $comment = trim((string) $payment->getPaymentSystemName());
            break;
        }

        return [
            'paid' => $order->isPaid(),
            'paymentComment' => $comment,
        ];
    }

    /**
     * @param list<string> $statusIds
     * @return array<string, string>
     */
    private function statusNames(array $statusIds): array
    {
        $statusIds = array_values(array_unique(array_filter($statusIds, static fn (string $id): bool => $id !== '')));
        if ($statusIds === []) {
            return [];
        }

        $names = [];
        $rows = StatusLangTable::getList([
            'select' => ['STATUS_ID', 'NAME'],
            'filter' => [
                '=LID' => self::LANGUAGE_ID,
                '@STATUS_ID' => $statusIds,
            ],
        ]);
        while ($row = $rows->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['STATUS_ID'] ?? ''));
            $name = trim((string) ($row['NAME'] ?? ''));
            if ($id !== '' && $name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, string>
     */
    private function pictures(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, static fn (int $id): bool => $id > 0)));
        if ($productIds === [] || !Loader::includeModule('iblock')) {
            return [];
        }

        $pictures = [];
        $elements = \CIBlockElement::GetList(
            [],
            ['ID' => $productIds],
            false,
            false,
            ['ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
        );
        while ($element = $elements->Fetch()) {
            if (!is_array($element)) {
                continue;
            }
            $fileId = (int) ($element['PREVIEW_PICTURE'] ?: $element['DETAIL_PICTURE']);
            $productId = (int) ($element['ID'] ?? 0);
            if ($productId <= 0 || $fileId <= 0) {
                continue;
            }
            $path = \CFile::GetPath($fileId);
            if (is_string($path) && $path !== '') {
                $pictures[$productId] = $this->absoluteUrl($path);
            }
        }

        return $pictures;
    }

    private function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $request = Application::getInstance()->getContext()->getRequest();
        $host = trim((string) $request->getHttpHost());
        if ($host === '') {
            return $path;
        }

        $scheme = $request->isHttps() ? 'https' : 'http';
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $scheme . '://' . $host . $path;
    }

    private function propertyText(mixed $property): string
    {
        if (!$property instanceof \Bitrix\Sale\EntityPropertyValue) {
            return '';
        }

        $value = $property->getValue();
        if (is_array($value)) {
            $value = reset($value);
        }

        return trim((string) $value);
    }

    private function timestamp(mixed $value): int
    {
        if ($value instanceof \Bitrix\Main\Type\DateTime) {
            return $value->getTimestamp();
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return 0;
    }

    private function money(mixed $value, string $currency): float
    {
        return (float) PriceMaths::roundByFormatCurrency((float) $value, $currency);
    }

    private function quantity(float $quantity): int|float
    {
        $rounded = round($quantity, 3);
        if (abs($rounded - round($rounded)) < 0.0001) {
            return (int) round($rounded);
        }

        return $rounded;
    }
}
