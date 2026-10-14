<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Sale\BusinessValue;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem\BaseServiceHandler;
use Bitrix\Sale\PaySystem\Manager as PaySystemManager;
use Bitrix\Sale\PaySystem\Service as PaySystemService;
use Bitrix\Sale\PriceMaths;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\RequestException;

/**
 * Card checkout through the stock bePaid pay system handler.
 * Order::save() here only stores the paid flag after a confirmed capture.
 */
final class BePaidAcquiring
{
    private const CHECKOUT_STATUS_URL = 'https://checkout.bepaid.by/ctp/api/checkouts/';

    private const STATUS_SUCCESSFUL = 'successful';

    /** @var list<string> */
    private const FINAL_FAILURE_STATUSES = ['error', 'failed', 'expired', 'deleted', 'declined'];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $this->assertSale();

        if (($payload['command'] ?? null) !== 'create') {
            return $this->failure('Ожидается команда create');
        }

        $paySystemId = $this->positiveId($payload['paymentMethodId'] ?? null);
        if ($paySystemId <= 0) {
            return $this->failure('Не указан способ оплаты');
        }

        $order = $this->findOrder($payload);
        if ($order === null) {
            return $this->failure('Заказ не найден');
        }

        if ($order->isCanceled()) {
            return $this->failure('Заказ отменён');
        }

        $payment = $this->findPayment($order, $paySystemId);
        if ($payment === null) {
            return $this->failure('Платёж не найден');
        }

        if ($payment->isPaid() || (string) $payment->getField('PS_STATUS') === 'Y') {
            return $this->failure('Заказ уже оплачен');
        }

        $service = PaySystemManager::getObjectById($paySystemId);
        if (!$service instanceof PaySystemService || !$this->isBePaidCheckout($service)) {
            return $this->failure('Способ оплаты не поддерживает онлайн-оплату');
        }

        $successUrl = $this->businessValue($service, $payment, 'BEPAID_SUCCESS_URL');
        $failureRegex = $this->failureRegex($service, $payment);
        if ($successUrl === '' || $failureRegex === '') {
            return $this->failure('Не заданы URL возврата платёжной системы');
        }

        $connection = Application::getConnection();
        $lockName = $this->lockName((int) $order->getId(), (int) $payment->getId());
        if (!$connection->lock($lockName, 15)) {
            return $this->failure('Не удалось создать платёж');
        }

        try {
            return $this->issueCheckout($order, $payment, $service, $successUrl, $failureRegex);
        } finally {
            $connection->unlock($lockName);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issueCheckout(
        Order $order,
        Payment $payment,
        PaySystemService $service,
        string $successUrl,
        string $failureRegex,
    ): array {
        $reloaded = Order::load((int) $order->getId());
        if (!$reloaded instanceof Order) {
            return $this->failure('Заказ не найден');
        }

        $current = $reloaded->getPaymentCollection()->getItemById((int) $payment->getId());
        if (!$current instanceof Payment) {
            return $this->failure('Платёж не найден');
        }

        if ($current->isPaid() || (string) $current->getField('PS_STATUS') === 'Y') {
            return $this->failure('Заказ уже оплачен');
        }

        $existingId = trim((string) $current->getField('PS_INVOICE_ID'));
        if ($existingId !== '') {
            $reused = $this->reuseOpenCheckout($service, $current, $existingId, $successUrl, $failureRegex);
            if ($reused !== null) {
                return $reused;
            }
        }

        $initResult = $service->initiatePay($current, null, BaseServiceHandler::STRING);
        if (!$initResult->isSuccess()) {
            return $this->failure($this->resultError($initResult->getErrorMessages(), 'Не удалось создать платёж'));
        }

        $paymentUrl = trim($initResult->getPaymentUrl());
        if (!$this->isBePaidUrl($paymentUrl)) {
            return $this->failure('Платёжная система не вернула ссылку на оплату');
        }

        $paymentId = trim((string) $current->getField('PS_INVOICE_ID'));
        if ($paymentId === '') {
            return $this->failure('Платёжная система не вернула идентификатор платежа');
        }

        $this->rememberPaymentUrl($current, $paymentUrl);

        return $this->created($paymentId, $paymentUrl, $successUrl, $failureRegex);
    }

    private function lockName(int $orderId, int $paymentId): string
    {
        $hash = substr(hash('sha256', Config::getSiteId() . ':' . $orderId . ':' . $paymentId), 0, 40);

        return 'bx_imshop_payment_' . $hash;
    }

    /**
     * A second create must keep the checkout IMSHOP is already polling.
     * A new token is issued only after the previous checkout has failed.
     *
     * @return array<string, mixed>|null
     */
    private function reuseOpenCheckout(
        PaySystemService $service,
        Payment $payment,
        string $paymentId,
        string $successUrl,
        string $failureRegex,
    ): ?array {
        $shopId = $this->businessValue($service, $payment, 'BEPAID_ID');
        $secret = $this->businessValue($service, $payment, 'BEPAID_SECRET_KEY');
        if ($shopId === '' || $secret === '') {
            return $this->failure('Не удалось создать платёж');
        }

        $fetched = $this->fetchCheckout($shopId, $secret, $paymentId);
        if ($fetched['state'] === 'pending') {
            return $this->reusedCheckout($payment, $paymentId, $successUrl, $failureRegex);
        }

        if ($fetched['state'] === 'error') {
            return null;
        }

        $checkout = $fetched['checkout'];
        if (!is_array($checkout)) {
            return $this->reusedCheckout($payment, $paymentId, $successUrl, $failureRegex);
        }

        $status = strtolower($this->text($checkout['status'] ?? null));
        if ($status === self::STATUS_SUCCESSFUL) {
            $gatewayPayment = $checkout['gateway_response']['payment'] ?? null;
            if (!is_array($gatewayPayment)) {
                return $this->reusedCheckout($payment, $paymentId, $successUrl, $failureRegex);
            }

            $synced = $this->applySuccessfulCheckout(
                $payment->getCollection()->getOrder(),
                $payment,
                $service,
                $paymentId,
                $gatewayPayment
            );
            if (($synced['success'] ?? false) !== true) {
                $error = $this->text($synced['error'] ?? null);

                return $this->failure($error !== '' ? $error : 'Не удалось создать платёж');
            }

            return $this->failure('Заказ уже оплачен');
        }

        if ($this->isFinalFailure($checkout, $status)) {
            return null;
        }

        $paymentUrl = $this->text($checkout['redirect_url'] ?? null);
        if ($this->isBePaidUrl($paymentUrl)) {
            $this->rememberPaymentUrl($payment, $paymentUrl);

            return $this->created($paymentId, $paymentUrl, $successUrl, $failureRegex);
        }

        return $this->reusedCheckout($payment, $paymentId, $successUrl, $failureRegex);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reusedCheckout(
        Payment $payment,
        string $paymentId,
        string $successUrl,
        string $failureRegex,
    ): ?array {
        $paymentUrl = $this->text($payment->getField('PS_STATUS_DESCRIPTION'));
        if (!$this->isBePaidUrl($paymentUrl)) {
            return $this->failure('Не удалось создать платёж');
        }

        return $this->created($paymentId, $paymentUrl, $successUrl, $failureRegex);
    }

    /**
     * @return array<string, mixed>
     */
    private function created(
        string $paymentId,
        string $paymentUrl,
        string $successUrl,
        string $failureRegex,
    ): array {
        return [
            'success' => true,
            'paymentId' => $paymentId,
            'paymentUrl' => $paymentUrl,
            'successRegex' => preg_quote($successUrl, '/'),
            'failureRegex' => $failureRegex,
        ];
    }

    private function rememberPaymentUrl(Payment $payment, string $paymentUrl): void
    {
        if (!$this->isBePaidUrl($paymentUrl)) {
            return;
        }

        if ((string) $payment->getField('PS_STATUS_DESCRIPTION') === $paymentUrl) {
            return;
        }

        $updated = $payment->setField('PS_STATUS_DESCRIPTION', $paymentUrl);
        if (!$updated->isSuccess()) {
            return;
        }

        $order = $payment->getCollection()->getOrder();
        $order->save();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function capture(array $payload): array
    {
        $this->assertSale();

        $paymentId = $this->text($payload['paymentId'] ?? null);
        if (($payload['command'] ?? null) !== 'capture') {
            return $this->captureFailure($paymentId, 'Ожидается команда capture');
        }

        if ($paymentId === '') {
            return $this->captureFailure('', 'Не указан идентификатор платежа');
        }

        $loaded = $this->findPaymentByInvoice($paymentId);
        if ($loaded === null) {
            return $this->captureFailure($paymentId, 'Платёж не найден');
        }

        $order = $loaded['order'];
        $payment = $loaded['payment'];
        $paySystemId = $this->positiveId($payload['paymentMethodId'] ?? null);
        if ($paySystemId <= 0) {
            return $this->captureFailure($paymentId, 'Не указан способ оплаты');
        }

        if ((int) $payment->getPaymentSystemId() !== $paySystemId) {
            return $this->captureFailure($paymentId, 'Способ оплаты не совпадает с платежом');
        }

        if ($order->isCanceled()) {
            return $this->captureFailure($paymentId, 'Заказ отменён');
        }

        if ($payment->isPaid() || (string) $payment->getField('PS_STATUS') === 'Y') {
            return $this->captured($paymentId);
        }

        $service = PaySystemManager::getObjectById((int) $payment->getPaymentSystemId());
        if (!$service instanceof PaySystemService || !$this->isBePaidCheckout($service)) {
            return $this->captureFailure($paymentId, 'Способ оплаты не поддерживает онлайн-оплату');
        }

        $shopId = $this->businessValue($service, $payment, 'BEPAID_ID');
        $secret = $this->businessValue($service, $payment, 'BEPAID_SECRET_KEY');
        if ($shopId === '' || $secret === '') {
            return $this->captureFailure($paymentId, 'Не удалось проверить оплату');
        }

        $fetched = $this->fetchCheckout($shopId, $secret, $paymentId);
        if ($fetched['state'] === 'pending') {
            return $this->pending($paymentId);
        }

        if ($fetched['state'] === 'error') {
            return $this->captureFailure($paymentId, 'Не удалось проверить оплату');
        }

        $checkout = $fetched['checkout'];
        if (!is_array($checkout)) {
            return $this->pending($paymentId);
        }

        $status = strtolower($this->text($checkout['status'] ?? null));
        if ($status === self::STATUS_SUCCESSFUL) {
            $gatewayPayment = $checkout['gateway_response']['payment'] ?? null;
            if (!is_array($gatewayPayment)) {
                return $this->pending($paymentId);
            }

            return $this->applySuccessfulCheckout($order, $payment, $service, $paymentId, $gatewayPayment);
        }

        if ($this->isFinalFailure($checkout, $status)) {
            return $this->captureFailure(
                $paymentId,
                $this->checkoutError($checkout, 'Оплата не прошла')
            );
        }

        return $this->pending($paymentId);
    }

    private function assertSale(): void
    {
        if (!Loader::includeModule('sale')) {
            throw new RequestException('Модули магазина недоступны', 503);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findOrder(array $payload): ?Order
    {
        $uuid = $this->text($payload['orderUuid'] ?? null);
        if ($uuid !== '') {
            $byUuid = $this->loadByFilter([
                '=XML_ID' => $uuid,
                '=LID' => Config::getSiteId(),
            ]);
            if ($byUuid !== null) {
                return $byUuid;
            }
        }

        $orderId = $this->text($payload['orderId'] ?? null);
        if ($orderId === '') {
            return null;
        }

        if (ctype_digit($orderId)) {
            $byId = Order::load((int) $orderId);
            if (
                $byId instanceof Order
                && (string) $byId->getField('LID') === Config::getSiteId()
            ) {
                return $byId;
            }
        }

        return $this->loadByFilter([
            '=ACCOUNT_NUMBER' => $orderId,
            '=LID' => Config::getSiteId(),
        ]);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function loadByFilter(array $filter): ?Order
    {
        $row = Order::getList([
            'select' => ['ID'],
            'filter' => $filter,
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

    private function findPayment(Order $order, int $paySystemId): ?Payment
    {
        $paid = null;
        foreach ($order->getPaymentCollection() as $payment) {
            if (!$payment instanceof Payment) {
                continue;
            }

            if ((int) $payment->getPaymentSystemId() !== $paySystemId) {
                continue;
            }

            if (!$payment->isPaid()) {
                return $payment;
            }

            $paid ??= $payment;
        }

        return $paid;
    }

    /**
     * @return array{order: Order, payment: Payment}|null
     */
    private function findPaymentByInvoice(string $paymentId): ?array
    {
        $row = Payment::getList([
            'select' => ['ID', 'ORDER_ID'],
            'filter' => ['=PS_INVOICE_ID' => $paymentId],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ])->fetch();

        if (!is_array($row)) {
            return null;
        }

        $orderId = (int) ($row['ORDER_ID'] ?? 0);
        $paymentRowId = (int) ($row['ID'] ?? 0);
        if ($orderId <= 0 || $paymentRowId <= 0) {
            return null;
        }

        $order = Order::load($orderId);
        if (!$order instanceof Order || (string) $order->getField('LID') !== Config::getSiteId()) {
            return null;
        }

        $payment = $order->getPaymentCollection()->getItemById($paymentRowId);

        return $payment instanceof Payment ? ['order' => $order, 'payment' => $payment] : null;
    }

    private function isBePaidCheckout(PaySystemService $service): bool
    {
        return strcasecmp((string) $service->getField('ACTION_FILE'), 'bepaid') === 0
            && (string) $service->getField('PS_MODE') === 'checkout';
    }

    private function isBePaidUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'https' && ($host === 'bepaid.by' || str_ends_with($host, '.bepaid.by'));
    }

    private function failureRegex(PaySystemService $service, Payment $payment): string
    {
        $parts = [];
        foreach (['BEPAID_DECLINE_URL', 'BEPAID_FAIL_URL', 'BEPAID_CANCEL_URL'] as $code) {
            $url = $this->businessValue($service, $payment, $code);
            if ($url === '') {
                continue;
            }

            $quoted = preg_quote($url, '/');
            if (!in_array($quoted, $parts, true)) {
                $parts[] = $quoted;
            }
        }

        return implode('|', $parts);
    }

    private function businessValue(PaySystemService $service, Payment $payment, string $code): string
    {
        $value = BusinessValue::get($code, $service->getConsumerName(), $payment);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array{state: 'checkout', checkout: array<string, mixed>}|array{state: 'pending'}|array{state: 'error'}
     */
    private function fetchCheckout(string $shopId, string $secret, string $paymentId): array
    {
        $client = new HttpClient([
            'socketTimeout' => 15,
            'streamTimeout' => 15,
            'disableSslVerification' => false,
        ]);
        $client->setHeader('Accept', 'application/json');
        $client->setHeader('Authorization', 'Basic ' . base64_encode($shopId . ':' . $secret));

        try {
            $raw = $client->get(self::CHECKOUT_STATUS_URL . rawurlencode($paymentId));
        } catch (\Throwable) {
            return ['state' => 'pending'];
        }

        $statusCode = (int) $client->getStatus();
        if ($statusCode === 408 || $statusCode === 429 || $statusCode >= 500 || $statusCode === 0) {
            return ['state' => 'pending'];
        }

        if ($statusCode >= 400 && $statusCode < 500) {
            return ['state' => 'error'];
        }

        if (!is_string($raw) || $raw === '' || $statusCode < 200 || $statusCode >= 300) {
            return ['state' => 'pending'];
        }

        try {
            $decoded = Json::decode($raw);
        } catch (\Throwable) {
            return ['state' => 'pending'];
        }

        $checkout = is_array($decoded) ? ($decoded['checkout'] ?? null) : null;
        if (!is_array($checkout)) {
            return ['state' => 'pending'];
        }

        return [
            'state' => 'checkout',
            'checkout' => $checkout,
        ];
    }

    /**
     * @param array<string, mixed> $transaction
     * @return array<string, mixed>
     */
    private function applySuccessfulCheckout(
        Order $order,
        Payment $payment,
        PaySystemService $service,
        string $paymentId,
        array $transaction,
    ): array {
        $amountMinor = $transaction['amount'] ?? null;
        if (!is_numeric($amountMinor)) {
            return $this->captureFailure($paymentId, 'Сумма платежа не совпадает с суммой оплаты');
        }

        $sum = ((float) $amountMinor) / 100;
        if (!$this->isSumCorrect($payment, $sum)) {
            return $this->captureFailure($paymentId, 'Сумма платежа не совпадает с суммой оплаты');
        }

        $uid = $this->text($transaction['uid'] ?? null);
        $fields = [
            'PS_STATUS_CODE' => $this->text($transaction['status'] ?? null) !== ''
                ? $this->text($transaction['status'] ?? null)
                : self::STATUS_SUCCESSFUL,
            'PS_STATUS_DESCRIPTION' => $uid !== '' ? 'Транзакция ' . $uid : 'Оплата подтверждена',
            'PS_SUM' => $sum,
            'PS_STATUS' => 'Y',
            'PS_CURRENCY' => $this->text($transaction['currency'] ?? null) !== ''
                ? $this->text($transaction['currency'] ?? null)
                : (string) $payment->getField('CURRENCY'),
            'PS_RESPONSE_DATE' => new DateTime(),
        ];

        $fieldsResult = $payment->setFields($fields);
        if (!$fieldsResult->isSuccess()) {
            return $this->captureFailure($paymentId, 'Не удалось сохранить статус оплаты');
        }

        if ($this->businessValue($service, $payment, 'PS_CHANGE_STATUS_PAY') === 'Y') {
            $paidResult = $payment->setPaid('Y');
            if (!$paidResult->isSuccess()) {
                return $this->captureFailure($paymentId, 'Не удалось отметить оплату');
            }
        }

        $saveResult = $order->save();
        if (!$saveResult->isSuccess()) {
            return $this->captureFailure($paymentId, 'Не удалось сохранить заказ');
        }

        return $this->captured($paymentId);
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function isFinalFailure(array $checkout, string $status): bool
    {
        if (in_array($status, self::FINAL_FAILURE_STATUSES, true)) {
            return true;
        }

        return ($checkout['expired'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function checkoutError(array $checkout, string $fallback): string
    {
        $message = $this->text($checkout['message'] ?? null);
        if ($message !== '') {
            return $message;
        }

        $paymentNode = $checkout['gateway_response']['payment'] ?? null;
        if (is_array($paymentNode)) {
            $paymentMessage = $this->text($paymentNode['message'] ?? null);
            if ($paymentMessage !== '') {
                return $paymentMessage;
            }
        }

        return $fallback;
    }

    private function isSumCorrect(Payment $payment, float $sum): bool
    {
        $currency = (string) $payment->getField('CURRENCY');

        return PriceMaths::roundByFormatCurrency($sum, $currency)
            === PriceMaths::roundByFormatCurrency($payment->getSum(), $currency);
    }

    /**
     * @param list<string> $messages
     */
    private function resultError(array $messages, string $fallback): string
    {
        foreach ($messages as $message) {
            $text = trim($message);
            if ($text !== '') {
                return $text;
            }
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $error): array
    {
        return [
            'success' => false,
            'error' => $error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureFailure(string $paymentId, string $error): array
    {
        return [
            'success' => false,
            'paymentId' => $paymentId,
            'paymentCaptured' => false,
            'error' => $error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pending(string $paymentId): array
    {
        return [
            'success' => true,
            'paymentId' => $paymentId,
            'paymentCaptured' => false,
            'pending' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captured(string $paymentId): array
    {
        return [
            'success' => true,
            'paymentId' => $paymentId,
            'paymentCaptured' => true,
        ];
    }

    private function text(mixed $raw): string
    {
        if (!is_string($raw) && !is_numeric($raw)) {
            return '';
        }

        return trim((string) $raw);
    }

    private function positiveId(mixed $raw): int
    {
        $value = $this->text($raw);
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
