<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Order;
use Bitrix\Sale\PriceMaths;
use Bx\Imshop\Integration\Config;

/**
 * Bitrix basket-rule gifts for an IMSHOP cart.
 * Gift\Manager cannot read a basket that already belongs to an order.
 */
final class BasketGifts
{
    public function __construct(
        private readonly CatalogItemResolver $items = new CatalogItemResolver(),
    ) {
    }

    /**
     * A repeated gift line. This endpoint writes appliedDiscounts only for gifts.
     *
     * @param array<string, mixed> $item
     */
    public function isEcho(array $item): bool
    {
        $gift = $item['gift'] ?? null;
        if (is_numeric($gift) && (float) $gift > 0) {
            return true;
        }

        $price = $item['price'] ?? null;
        if (is_numeric($price) && (float) $price == 0.0) {
            return true;
        }

        $applied = $item['appliedDiscounts'] ?? null;

        return is_array($applied) && $applied !== [];
    }

    /**
     * @param list<int> $groups
     * @return array{groups: list<list<array<string, mixed>>>, lines: list<array<string, mixed>>}
     */
    public function present(Order $order, array $payload, array $groups): array
    {
        $offers = $this->offers($order);
        $choices = $this->choices($payload);
        $groupsOut = [];
        $lines = [];
        $seen = [];

        foreach ($offers as $offer) {
            if ($offer['type'] === 'all') {
                $groupsOut[] = [[
                    'id' => $offer['id'],
                    'name' => $offer['name'],
                    'sumsWithBonuses' => true,
                    'gifts' => $this->refs($offer['products']),
                ]];
                $this->appendLines($lines, $seen, $offer['products'], $offer['id'], $groups);
                continue;
            }

            $groupsOut[] = [[
                'id' => $offer['id'],
                'name' => $offer['name'],
                'sumsWithBonuses' => false,
                'giftOptions' => $this->refs($offer['products']),
                'giftOptionsLimit' => 1,
            ]];
            $picked = $choices[$offer['id']] ?? [];
            $chosen = [];
            foreach ($picked as $productId) {
                if (count($chosen) >= 1) {
                    break;
                }
                if (in_array($productId, $offer['products'], true)) {
                    $chosen[] = $productId;
                }
            }
            $this->appendLines($lines, $seen, $chosen, $offer['id'], $groups);
        }

        return [
            'groups' => $groupsOut,
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applyToOrder(Order $order, array $payload): void
    {
        $granted = [];
        foreach ($this->present($order, $payload, [])['lines'] as $line) {
            $productId = (int) ($line['id'] ?? 0);
            if ($productId > 0) {
                $granted[$productId] = true;
            }
        }
        if ($granted === []) {
            return;
        }

        $basket = $order->getBasket();
        if ($basket === null) {
            return;
        }

        $basketItems = [];
        foreach ($basket as $basketItem) {
            if ($basketItem instanceof BasketItem && !$basketItem->isBundleChild()) {
                $basketItems[] = $basketItem;
            }
        }

        $resolved = $this->items->resolve($payload);
        if (count($resolved) === count($basketItems)) {
            foreach ($resolved as $index => $line) {
                $productId = (int) $line['productId'];
                $source = $this->sourceItem($payload, $index);
                if (isset($granted[$productId]) && is_array($source) && $this->isEcho($source)) {
                    $this->zero($basketItems[$index]);
                    unset($granted[$productId]);
                }
            }

            return;
        }

        foreach ($basketItems as $basketItem) {
            $productId = (int) $basketItem->getProductId();
            if (!isset($granted[$productId]) || !$this->payloadHasEcho($payload, $productId)) {
                continue;
            }
            $this->zero($basketItem);
            unset($granted[$productId]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{id: string, name: string, type: string, products: list<int>}>
     */
    private function offers(Order $order): array
    {
        $discount = $order->getDiscount();
        if ($discount === null) {
            return [];
        }

        $result = $discount->getApplyResult(true);
        $list = is_array($result['FULL_DISCOUNT_LIST'] ?? null) ? $result['FULL_DISCOUNT_LIST'] : [];
        $offers = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $data = \CSaleActionGiftCtrlGroup::ProvideGiftProductData($row);
            if (!is_array($data) || $data === []) {
                continue;
            }

            $id = trim((string) ($row['ID'] ?? ''));
            $name = trim((string) ($row['NAME'] ?? ''));
            if ($name === '') {
                $name = 'Подарок';
            }
            foreach ($data as $index => $gift) {
                if (!is_array($gift)) {
                    continue;
                }
                $type = (string) ($gift['Type'] ?? '');
                if ($type !== 'one' && $type !== 'all') {
                    continue;
                }
                $products = $this->availableProducts($gift['GiftValue'] ?? []);
                if ($products === []) {
                    continue;
                }
                $offerId = $id !== '' ? $id : 'gift';
                if ($index > 0) {
                    $offerId .= ':' . $index;
                }
                $offers[] = [
                    'id' => $offerId,
                    'name' => $name,
                    'type' => $type,
                    'products' => $products,
                ];
            }
        }

        return $offers;
    }

    /**
     * @param mixed $values
     * @return list<int>
     */
    private function availableProducts(mixed $values): array
    {
        if (!is_array($values)) {
            $values = [$values];
        }

        $products = [];
        foreach ($values as $value) {
            $productId = (int) $value;
            if ($productId > 0 && !in_array($productId, $products, true) && $this->isAvailable($productId)) {
                $products[] = $productId;
            }
        }

        return $products;
    }

    private function isAvailable(int $productId): bool
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, list<int>>
     */
    private function choices(array $payload): array
    {
        $groups = $payload['promoGroup'] ?? null;
        if (!is_array($groups)) {
            return [];
        }

        $choices = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $id = trim((string) ($group['id'] ?? ''));
            $gifts = $group['gifts'] ?? null;
            if ($id === '' || !is_array($gifts)) {
                continue;
            }
            foreach ($gifts as $gift) {
                if (!is_array($gift)) {
                    continue;
                }
                $productId = (int) ($gift['id'] ?? 0);
                if ($productId <= 0) {
                    $productId = $this->items->catalogProductId($gift);
                }
                if ($productId > 0) {
                    $choices[$id][] = $productId;
                }
            }
        }

        return $choices;
    }

    /**
     * @param list<int> $products
     * @return list<array{id: string, quantity: int}>
     */
    private function refs(array $products): array
    {
        $refs = [];
        foreach ($products as $productId) {
            $refs[] = [
                'id' => (string) $productId,
                'quantity' => 1,
            ];
        }

        return $refs;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<int, true> $seen
     * @param list<int> $products
     * @param list<int> $groups
     */
    private function appendLines(array &$lines, array &$seen, array $products, string $offerId, array $groups): void
    {
        foreach ($products as $productId) {
            if (isset($seen[$productId])) {
                continue;
            }
            $line = $this->line($productId, $offerId, $groups);
            if ($line === null) {
                continue;
            }
            $seen[$productId] = true;
            $lines[] = $line;
        }
    }

    /**
     * @param list<int> $groups
     * @return array<string, mixed>|null
     */
    private function line(int $productId, string $offerId, array $groups): ?array
    {
        $optimal = \CCatalogProduct::GetOptimalPrice(
            $productId,
            1,
            $groups,
            'N',
            [],
            Config::getSiteId()
        );
        $resultPrice = is_array($optimal) ? ($optimal['RESULT_PRICE'] ?? null) : null;
        if (!is_array($resultPrice)) {
            return null;
        }

        $price = PriceMaths::roundPrecision((float) ($resultPrice['BASE_PRICE'] ?? 0));

        return [
            'id' => (string) $productId,
            'name' => $this->name($productId),
            'price' => $price,
            'discount' => $price,
            'quantity' => 1,
            'subtotal' => 0,
            'gift' => 1,
            'appliedDiscounts' => [$offerId],
            'canBePurchased' => true,
            'selected' => true,
        ];
    }

    private function name(int $productId): string
    {
        if (!Loader::includeModule('iblock')) {
            return '';
        }

        $element = ElementTable::getByPrimary($productId, ['select' => ['NAME']])->fetch();
        $name = is_array($element) ? trim((string) ($element['NAME'] ?? '')) : '';

        return $name;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function sourceItem(array $payload, int $resolvedIndex): ?array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        $cursor = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($this->items->catalogProductId($item) <= 0 || !$this->hasQuantity($item)) {
                continue;
            }
            if ($cursor === $resolvedIndex) {
                return $item;
            }
            $cursor++;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadHasEcho(array $payload, int $productId): bool
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item) || !$this->isEcho($item)) {
                continue;
            }
            if ($this->items->catalogProductId($item) === $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function hasQuantity(array $item): bool
    {
        $raw = $item['quantity'] ?? 0;

        return is_numeric($raw) && (float) $raw > 0;
    }

    private function zero(BasketItem $basketItem): void
    {
        $base = (float) $basketItem->getBasePrice();
        if ($base <= 0) {
            $base = (float) $basketItem->getPrice();
        }

        $basketItem->setFields([
            'CUSTOM_PRICE' => 'Y',
            'BASE_PRICE' => $base,
            'PRICE' => 0,
            'DISCOUNT_PRICE' => $base,
        ]);
    }
}
