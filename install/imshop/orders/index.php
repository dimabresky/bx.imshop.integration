<?php

/**
 * IMSHOP order placement webhook. Business logic lives in bx.imshop.integration.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('BX_SECURITY_SESSION_READONLY', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$endpoint = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/bx.imshop.integration/public/endpoint.php';
if (!is_file($endpoint)) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    echo json_encode(
        [
            'orders' => [],
            'message' => 'Модуль интеграции IMSHOP не установлен',
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return;
}

require $endpoint;
