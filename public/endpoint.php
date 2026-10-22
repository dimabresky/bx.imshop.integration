<?php

/**
 * IMSHOP webhook entry. The public script is /local/imshop/<path>/index.php.
 * The webhook code is the path under /local/imshop/, for example orders/create.
 */

use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
$imshopRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/') . '/local/imshop';
$webhookCode = str_starts_with($scriptDir . '/', $imshopRoot . '/')
    ? trim(substr($scriptDir, strlen($imshopRoot)), '/')
    : '';

if (!Loader::includeModule('bx.imshop.integration')) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    if (
        $webhookCode === 'otp'
        || $webhookCode === 'otp/confirm'
        || $webhookCode === 'users'
        || $webhookCode === 'users/update'
        || $webhookCode === 'users/otp'
    ) {
        $payload = [
            'error' => [
                'message' => 'Модуль интеграции IMSHOP не установлен',
            ],
        ];
    } elseif (
        $webhookCode === 'payments/create'
        || $webhookCode === 'payments/check'
        || $webhookCode === 'users/otp/confirm'
    ) {
        $payload = [
            'success' => false,
            'error' => 'Модуль интеграции IMSHOP не установлен',
        ];
    } elseif ($webhookCode === 'availability') {
        $payload = [
            'warehouses' => [],
            'availability' => [],
            'message' => 'Модуль интеграции IMSHOP не установлен',
        ];
    } elseif ($webhookCode === 'basket') {
        $payload = [
            'items' => [],
            'message' => 'Модуль интеграции IMSHOP не установлен',
        ];
    } elseif ($webhookCode === 'orders/status') {
        $payload = [
            'success' => false,
            'error' => 'Модуль интеграции IMSHOP не установлен',
        ];
    } else {
        $listKey = $webhookCode === 'payments'
            ? 'payments'
            : (str_starts_with($webhookCode, 'orders/') ? 'orders' : 'deliveries');
        $payload = [
            $listKey => [],
            'message' => 'Модуль интеграции IMSHOP не установлен',
        ];
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return;
}

(new \Bx\Imshop\Integration\Http\FrontController())->run($webhookCode);
