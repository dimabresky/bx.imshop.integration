<?php

/**
 * Autoload map for bx.imshop.integration.
 */

use Bitrix\Main\Loader;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\AuthGuard;
use Bx\Imshop\Integration\Http\FrontController;
use Bx\Imshop\Integration\Http\JsonResponder;
use Bx\Imshop\Integration\Http\RequestException;
use Bx\Imshop\Integration\Http\RequestLogger;
use Bx\Imshop\Integration\Sale\CalculationOrderFactory;
use Bx\Imshop\Integration\Sale\CatalogItemResolver;
use Bx\Imshop\Integration\Sale\DeliveryCalculator;
use Bx\Imshop\Integration\Sale\ImshopDeliveryMapper;
use Bx\Imshop\Integration\Sale\ImshopPaymentMapper;
use Bx\Imshop\Integration\Sale\LocationResolver;
use Bx\Imshop\Integration\Sale\OrderCreator;
use Bx\Imshop\Integration\Sale\PaymentCalculator;
use Bx\Imshop\Integration\Webhook\DeliveryWebhook;
use Bx\Imshop\Integration\Webhook\OrderWebhook;
use Bx\Imshop\Integration\Webhook\PaymentWebhook;
use Bx\Imshop\Integration\Webhook\Registry;
use Bx\Imshop\Integration\Webhook\WebhookHandlerInterface;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loader::registerAutoLoadClasses(
    'bx.imshop.integration',
    [
        Config::class => 'lib/Config.php',
        RequestException::class => 'lib/Http/RequestException.php',
        AuthGuard::class => 'lib/Http/AuthGuard.php',
        JsonResponder::class => 'lib/Http/JsonResponder.php',
        RequestLogger::class => 'lib/Http/RequestLogger.php',
        FrontController::class => 'lib/Http/FrontController.php',
        WebhookHandlerInterface::class => 'lib/Webhook/WebhookHandlerInterface.php',
        Registry::class => 'lib/Webhook/Registry.php',
        DeliveryWebhook::class => 'lib/Webhook/DeliveryWebhook.php',
        PaymentWebhook::class => 'lib/Webhook/PaymentWebhook.php',
        OrderWebhook::class => 'lib/Webhook/OrderWebhook.php',
        CatalogItemResolver::class => 'lib/Sale/CatalogItemResolver.php',
        LocationResolver::class => 'lib/Sale/LocationResolver.php',
        CalculationOrderFactory::class => 'lib/Sale/CalculationOrderFactory.php',
        ImshopDeliveryMapper::class => 'lib/Sale/ImshopDeliveryMapper.php',
        DeliveryCalculator::class => 'lib/Sale/DeliveryCalculator.php',
        ImshopPaymentMapper::class => 'lib/Sale/ImshopPaymentMapper.php',
        PaymentCalculator::class => 'lib/Sale/PaymentCalculator.php',
        OrderCreator::class => 'lib/Sale/OrderCreator.php',
    ]
);
