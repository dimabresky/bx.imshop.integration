<?php

/**
 * IMSHOP webhook entry. The public script is /local/imshop/<code>/index.php.
 */

use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$webhookCode = basename(dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));

if (!Loader::includeModule('bx.imshop.integration')) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    $listKey = $webhookCode === 'payments' ? 'payments' : 'deliveries';
    echo json_encode(
        [
            $listKey => [],
            'message' => 'Модуль интеграции IMSHOP не установлен',
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return;
}

(new \Bx\Imshop\Integration\Http\FrontController())->run($webhookCode);
