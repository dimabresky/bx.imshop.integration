<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Loader;
use Bitrix\Sale\Delivery\Services\Manager as DeliveryManager;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem\Manager as PaySystemManager;
use Bitrix\Sale\Shipment;
use Bx\Imshop\Integration\Http\RequestException;

/**
 * Same pay-system list as SaleOrderAjax::initPayment().
 * The component itself is not executed: it depends on the visitor session and fuser basket.
 */
final class PaymentCalculator
{
    public function __construct(
        private readonly CalculationOrderFactory $orders = new CalculationOrderFactory(),
        private readonly ImshopPaymentMapper $mapper = new ImshopPaymentMapper(),
    ) {
    }

    /**
     * bonusesSpent, authorizedBonusType, promoGroup and hasPreorderItems are accepted
     * by the protocol and are not applied. Aspro bonus write-off is tied to the checkout session.
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
            $this->applyDelivery($order, $payload);
            $order->doFinalAction(true);

            return ['payments' => $this->restrictedPayments($order)];
        } finally {
            DiscountCouponsManager::clear(true);
        }
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
        } catch (\Bitrix\Main\SystemException) {
            return;
        }

        if ($service === null) {
            return;
        }

        $shipment->setField('CUSTOM_PRICE_DELIVERY', 'N');
        $storeId = $this->positiveId($payload['pickupLocationId'] ?? null);
        if ($storeId > 0) {
            $shipment->setStoreId($storeId);
        }

        $shipment->setDeliveryService($service);
        $order->getShipmentCollection()->calculateDelivery();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function restrictedPayments(Order $order): array
    {
        $paymentCollection = $order->getPaymentCollection();
        $remainingSum = $order->getPrice() - $paymentCollection->getSum();
        $payment = $paymentCollection->createItem();
        $payment->setField('SUM', $remainingSum > 0 ? $remainingSum : 0);

        $paySystems = PaySystemManager::getListWithRestrictions($payment);
        $innerId = PaySystemManager::getInnerPaySystemId();
        $payments = [];

        foreach ($paySystems as $paySystem) {
            if (!is_array($paySystem)) {
                continue;
            }

            if ($innerId > 0 && (int) ($paySystem['ID'] ?? 0) === $innerId) {
                continue;
            }

            $mapped = $this->mapper->map($paySystem);
            if ($mapped !== null) {
                $payments[] = $mapped;
            }
        }

        return $payments;
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
