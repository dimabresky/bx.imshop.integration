<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;

/**
 * IMSHOP line items to catalog element ids.
 *
 * The DNK feed writes offer id as the element id and omits group_id,
 * so configurationId, then privateId, then id is the element id.
 */
final class CatalogItemResolver
{
    /**
     * Catalog element id for an IMSHOP line, or 0 when the offer is unknown.
     *
     * @param array<string, mixed> $item
     */
    public function catalogProductId(array $item): int
    {
        if (!Loader::includeModule('catalog')) {
            return 0;
        }

        $productId = $this->productId($item);
        if ($productId <= 0) {
            return 0;
        }

        $product = ProductTable::getByPrimary($productId, ['select' => ['ID']])->fetch();

        return is_array($product) ? $productId : 0;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{productId: int, quantity: float, lineKey?: string}>
     */
    public function resolve(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $resolved = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $this->catalogProductId($item);
            $quantity = $this->quantity($item);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $line = [
                'productId' => $productId,
                'quantity' => $quantity,
            ];
            $lineKey = $item['lineKey'] ?? null;
            if (is_string($lineKey) && $lineKey !== '') {
                $line['lineKey'] = $lineKey;
            }

            $resolved[] = $line;
        }

        return $resolved;
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
}
