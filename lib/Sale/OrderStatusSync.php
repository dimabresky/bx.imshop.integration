<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Sale\Internals\StatusLangTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\RequestException;
use Bx\Imshop\Integration\Http\RequestLogger;

/**
 * IMSHOP order status in both directions.
 * Order::save() here applies a status sent by IMSHOP.
 * A site status change is posted to IMSHOP when the sync URL is set.
 */
final class OrderStatusSync
{
    private const LANGUAGE_ID = 'ru';

    private const REASON_MAX = 255;

    private static bool $pushing = false;

    /**
     * Sale events still run under DisableEventsCheck. This blocks the echo back to IMSHOP.
     */
    private static bool $applying = false;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        self::$applying = true;
        try {
            return $this->change($payload);
        } finally {
            self::$applying = false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function change(array $payload): array
    {
        if (!Loader::includeModule('sale')) {
            throw new RequestException('Модули магазина недоступны', 503);
        }

        $id = trim((string) ($payload['id'] ?? ''));
        $code = trim((string) ($payload['status'] ?? ''));
        if ($id === '') {
            throw new RequestException('Не передан номер заказа', 400);
        }
        if ($code === '') {
            throw new RequestException('Не передан статус', 400);
        }

        $mapped = Config::orderStatusMap()[$code] ?? null;
        $statusId = is_array($mapped) ? trim((string) ($mapped['status_id'] ?? '')) : '';
        if ($statusId === '') {
            throw new RequestException('Статус не сопоставлен', 200);
        }

        $order = $this->find($id);
        if ($order === null) {
            throw new RequestException('Заказ не найден', 200);
        }

        $message = is_array($mapped) ? trim((string) ($mapped['message'] ?? '')) : '';
        if ($message === '') {
            $message = $this->statusName($statusId);
        }

        $sameStatus = (string) $order->getField('STATUS_ID') === $statusId;
        $alreadyCanceled = (string) $order->getField('CANCELED') === 'Y';
        if (!$sameStatus || ($code === 'canceled' && !$alreadyCanceled)) {
            $order->setField('STATUS_ID', $statusId);
            if ($code === 'canceled') {
                $order->setField('CANCELED', 'Y');
                $reason = trim((string) ($payload['reason'] ?? ''));
                if ($reason !== '') {
                    $order->setField('REASON_CANCELED', mb_substr($reason, 0, self::REASON_MAX));
                }
            }

            $saved = $order->save();
            if (!$saved->isSuccess()) {
                throw new RequestException('Не удалось изменить статус заказа', 200);
            }
        }

        $response = [
            'success' => true,
            'status' => $statusId,
        ];
        if ($message !== '') {
            $response['message'] = $message;
        }

        return $response;
    }

    public static function onSaleStatusOrderChange(Event $event): void
    {
        if (self::$pushing || self::$applying || !Config::isEnabled()) {
            return;
        }

        $order = $event->getParameter('ENTITY');
        if (!$order instanceof Order) {
            return;
        }
        if ((string) $order->getField('LID') !== Config::getSiteId()) {
            return;
        }

        $statusId = trim((string) $event->getParameter('VALUE'));
        if ($statusId === '') {
            return;
        }

        self::$pushing = true;
        try {
            (new self())->push($order, $statusId);
        } catch (\Throwable $exception) {
            RequestLogger::error('status-sync', [
                'orderId' => $order->getId(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            self::$pushing = false;
        }
    }

    private function push(Order $order, string $statusId): void
    {
        $url = Config::statusSyncUrl();
        if ($url === '') {
            return;
        }
        if (preg_match('#^https://#i', $url) !== 1) {
            RequestLogger::warning('status-sync', [
                'orderId' => $order->getId(),
                'message' => 'URL синхронизации должен начинаться с https://',
            ]);

            return;
        }

        $map = Config::orderStatusMap();
        $code = '';
        $message = '';
        foreach (Config::ORDER_STATUS_CODES as $candidate) {
            $row = $map[$candidate] ?? null;
            if (!is_array($row) || trim((string) ($row['status_id'] ?? '')) !== $statusId) {
                continue;
            }
            $code = $candidate;
            $message = trim((string) ($row['message'] ?? ''));
            break;
        }
        if ($code === '') {
            return;
        }
        if ($message === '') {
            $message = $this->statusName($statusId);
        }

        $body = [
            'id' => (string) $order->getId(),
            'code' => $code,
        ];
        if ($message !== '') {
            $body['message'] = $message;
        }

        $userId = $this->digits($this->propertyText($order->getPropertyCollection()->getPhone()));
        if ($userId !== '') {
            $body['userId'] = $userId;
        }

        $paymentId = $order->isPaid() ? $this->paymentId($order) : '';
        if ($paymentId !== '') {
            $body['payment'] = [
                'paid' => true,
                'paymentId' => $paymentId,
            ];
        }

        $client = new HttpClient([
            'socketTimeout' => 10,
            'streamTimeout' => 10,
            'disableSslVerification' => false,
        ]);
        $client->setHeader('Content-Type', 'application/json');
        $client->setHeader('Accept', 'application/json');
        $client->setHeader('app-locale', self::LANGUAGE_ID);
        $token = Config::statusSyncToken();
        if ($token !== '') {
            $client->setHeader('Authorization', 'Bearer ' . $token);
        }

        $client->post($url, Json::encode($body));
        $statusCode = (int) $client->getStatus();
        if ($statusCode < 200 || $statusCode >= 300) {
            RequestLogger::warning('status-sync', [
                'orderId' => $order->getId(),
                'status' => $statusCode,
            ]);
        }
    }

    private function find(string $id): ?Order
    {
        $siteId = Config::getSiteId();
        if (preg_match('/^[1-9]\d*$/', $id) === 1) {
            $loaded = Order::load((int) $id);
            if ($loaded instanceof Order && (string) $loaded->getField('LID') === $siteId) {
                return $loaded;
            }
        }

        $row = Order::getList([
            'select' => ['ID'],
            'filter' => [
                '=ACCOUNT_NUMBER' => $id,
                '=LID' => $siteId,
            ],
            'limit' => 1,
        ])->fetch();
        $orderId = is_array($row) ? (int) ($row['ID'] ?? 0) : 0;
        if ($orderId <= 0) {
            return null;
        }

        $order = Order::load($orderId);

        return $order instanceof Order ? $order : null;
    }

    private function paymentId(Order $order): string
    {
        foreach ($order->getPaymentCollection() as $payment) {
            if (!$payment instanceof Payment || $payment->isInner() || !$payment->isPaid()) {
                continue;
            }
            $invoice = trim((string) $payment->getField('PS_INVOICE_ID'));

            return $invoice !== '' ? $invoice : (string) $payment->getId();
        }

        return '';
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

    private function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function statusName(string $statusId): string
    {
        $row = StatusLangTable::getRow([
            'select' => ['NAME'],
            'filter' => [
                '=STATUS_ID' => $statusId,
                '=LID' => self::LANGUAGE_ID,
            ],
        ]);

        return is_array($row) ? trim((string) ($row['NAME'] ?? '')) : '';
    }
}
