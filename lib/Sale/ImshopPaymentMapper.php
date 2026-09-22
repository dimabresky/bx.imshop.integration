<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Context;

/**
 * Bitrix pay system row to an IMSHOP payments item.
 *
 * IS_CASH: Y cash, A acquiring (card in the app), N non-cash on receipt.
 */
final class ImshopPaymentMapper
{
    /**
     * @param array<string, mixed> $paySystem
     * @return array<string, mixed>|null
     */
    public function map(array $paySystem): ?array
    {
        $id = (int) ($paySystem['ID'] ?? 0);
        $title = $this->plainText((string) ($paySystem['PSA_NAME'] ?? ''));
        if ($title === '') {
            $title = $this->plainText((string) ($paySystem['NAME'] ?? ''));
        }
        if ($id <= 0 || $title === '') {
            return null;
        }

        $payment = [
            'id' => (string) $id,
            'title' => $title,
            'type' => $this->type((string) ($paySystem['IS_CASH'] ?? '')),
        ];

        $description = $this->plainText((string) ($paySystem['DESCRIPTION'] ?? ''));
        if ($description !== '') {
            $payment['description'] = $description;
        }

        $icon = $this->iconUrl($paySystem['LOGOTIP'] ?? null);
        if ($icon !== '') {
            $payment['icon'] = $icon;
        }

        return $payment;
    }

    private function type(string $isCash): string
    {
        return match ($isCash) {
            'Y' => 'cash',
            'A' => 'card',
            default => 'card_on_delivery',
        };
    }

    private function iconUrl(mixed $fileId): string
    {
        if (!is_numeric($fileId) || (int) $fileId <= 0) {
            return '';
        }

        $path = \CFile::GetPath((int) $fileId);
        if (!is_string($path) || $path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $request = Context::getCurrent()->getRequest();
        $host = $request->getHttpHost();
        if ($host === '') {
            return '';
        }

        $scheme = $request->isHttps() ? 'https' : 'http';

        return $scheme . '://' . $host . $path;
    }

    private function plainText(string $value): string
    {
        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
