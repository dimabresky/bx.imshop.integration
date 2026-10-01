<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Catalog\StoreProductTable;
use Bitrix\Main\Loader;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Order;

/**
 * Bitrix stores that can fulfill every buyable line of the order.
 */
final class StoreStockFilter
{
    private const QUANTITY_EPSILON = 0.000001;

    /**
     * @param list<int> $storeIds
     * @return list<int>
     */
    public function havingAllProducts(array $storeIds, Order $order): array
    {
        $storeIds = $this->storeIds($storeIds);
        if ($storeIds === []) {
            return [];
        }

        $required = $this->requiredQuantities($order);
        if ($required === []) {
            return $storeIds;
        }

        if (!Loader::includeModule('catalog')) {
            return [];
        }

        $available = $this->availableByStore($storeIds, array_keys($required));
        $matched = [];
        foreach ($storeIds as $storeId) {
            if ($this->covers($available[$storeId] ?? [], $required)) {
                $matched[] = $storeId;
            }
        }

        return $matched;
    }

    /**
     * @param list<int> $storeIds
     * @return list<int>
     */
    private function storeIds(array $storeIds): array
    {
        $ids = [];
        foreach ($storeIds as $storeId) {
            if ($storeId > 0) {
                $ids[$storeId] = $storeId;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<int, float>
     */
    private function requiredQuantities(Order $order): array
    {
        $basket = $order->getBasket();
        if ($basket === null) {
            return [];
        }

        $required = [];
        /** @var BasketItem $item */
        foreach ($basket as $item) {
            $productId = (int) $item->getProductId();
            $quantity = (float) $item->getQuantity();
            if ($productId <= 0 || $quantity <= 0 || !$item->canBuy()) {
                continue;
            }

            $required[$productId] = ($required[$productId] ?? 0.0) + $quantity;
        }

        return $required;
    }

    /**
     * @param list<int> $storeIds
     * @param list<int> $productIds
     * @return array<int, array<int, float>>
     */
    private function availableByStore(array $storeIds, array $productIds): array
    {
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

    /**
     * @param array<int, float> $available
     * @param array<int, float> $required
     */
    private function covers(array $available, array $required): bool
    {
        foreach ($required as $productId => $quantity) {
            $stock = $available[$productId] ?? 0.0;
            if ($stock + self::QUANTITY_EPSILON < $quantity) {
                return false;
            }
        }

        return true;
    }
}
