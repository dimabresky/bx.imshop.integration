<?php

/**
 * Autoload map for bx.imshop.integration.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bx\Imshop\Integration\Config;
use Bx\Imshop\Integration\Http\AuthGuard;
use Bx\Imshop\Integration\Http\FrontController;
use Bx\Imshop\Integration\Http\JsonResponder;
use Bx\Imshop\Integration\Http\RequestException;
use Bx\Imshop\Integration\Http\RequestLogger;
use Bx\Imshop\Integration\Sale\AvailabilityCalculator;
use Bx\Imshop\Integration\Sale\BasketCalculator;
use Bx\Imshop\Integration\Sale\BasketGifts;
use Bx\Imshop\Integration\Sale\BePaidAcquiring;
use Bx\Imshop\Integration\Sale\CalculationOrderFactory;
use Bx\Imshop\Integration\Sale\CatalogItemResolver;
use Bx\Imshop\Integration\Sale\DeliveryCalculator;
use Bx\Imshop\Integration\Sale\ImshopDeliveryMapper;
use Bx\Imshop\Integration\Sale\ImshopPaymentMapper;
use Bx\Imshop\Integration\Sale\LocationResolver;
use Bx\Imshop\Integration\Sale\OrderCreator;
use Bx\Imshop\Integration\Sale\OrderHistory;
use Bx\Imshop\Integration\Sale\OrderStatusSync;
use Bx\Imshop\Integration\Sale\PaymentCalculator;
use Bx\Imshop\Integration\Sale\StoreMap;
use Bx\Imshop\Integration\Sale\StoreStockFilter;
use Bx\Imshop\Integration\User\OrderBuyer;
use Bx\Imshop\Integration\User\PhoneCode;
use Bx\Imshop\Integration\User\PhoneOtp;
use Bx\Imshop\Integration\User\PhoneUserFinder;
use Bx\Imshop\Integration\User\UserAccount;
use Bx\Imshop\Integration\User\UserProfile;
use Bx\Imshop\Integration\Webhook\BasketWebhook;
use Bx\Imshop\Integration\Webhook\CheckQuantityWebhook;
use Bx\Imshop\Integration\Webhook\DeliveryWebhook;
use Bx\Imshop\Integration\Webhook\OrderHistoryWebhook;
use Bx\Imshop\Integration\Webhook\OrderStatusWebhook;
use Bx\Imshop\Integration\Webhook\OrderWebhook;
use Bx\Imshop\Integration\Webhook\StoreMapWebhook;
use Bx\Imshop\Integration\Webhook\OtpConfirmWebhook;
use Bx\Imshop\Integration\Webhook\OtpRequestWebhook;
use Bx\Imshop\Integration\Webhook\PaymentCheckWebhook;
use Bx\Imshop\Integration\Webhook\PaymentCreateWebhook;
use Bx\Imshop\Integration\Webhook\PaymentWebhook;
use Bx\Imshop\Integration\Webhook\Registry;
use Bx\Imshop\Integration\Webhook\UserGetWebhook;
use Bx\Imshop\Integration\Webhook\UserOtpConfirmWebhook;
use Bx\Imshop\Integration\Webhook\UserOtpRequestWebhook;
use Bx\Imshop\Integration\Webhook\UserUpdateWebhook;
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
        BasketWebhook::class => 'lib/Webhook/BasketWebhook.php',
        CheckQuantityWebhook::class => 'lib/Webhook/CheckQuantityWebhook.php',
        DeliveryWebhook::class => 'lib/Webhook/DeliveryWebhook.php',
        PaymentCheckWebhook::class => 'lib/Webhook/PaymentCheckWebhook.php',
        PaymentCreateWebhook::class => 'lib/Webhook/PaymentCreateWebhook.php',
        PaymentWebhook::class => 'lib/Webhook/PaymentWebhook.php',
        OrderWebhook::class => 'lib/Webhook/OrderWebhook.php',
        OrderHistoryWebhook::class => 'lib/Webhook/OrderHistoryWebhook.php',
        OrderStatusWebhook::class => 'lib/Webhook/OrderStatusWebhook.php',
        StoreMapWebhook::class => 'lib/Webhook/StoreMapWebhook.php',
        AvailabilityCalculator::class => 'lib/Sale/AvailabilityCalculator.php',
        BasketCalculator::class => 'lib/Sale/BasketCalculator.php',
        BasketGifts::class => 'lib/Sale/BasketGifts.php',
        BePaidAcquiring::class => 'lib/Sale/BePaidAcquiring.php',
        CatalogItemResolver::class => 'lib/Sale/CatalogItemResolver.php',
        LocationResolver::class => 'lib/Sale/LocationResolver.php',
        CalculationOrderFactory::class => 'lib/Sale/CalculationOrderFactory.php',
        ImshopDeliveryMapper::class => 'lib/Sale/ImshopDeliveryMapper.php',
        DeliveryCalculator::class => 'lib/Sale/DeliveryCalculator.php',
        ImshopPaymentMapper::class => 'lib/Sale/ImshopPaymentMapper.php',
        PaymentCalculator::class => 'lib/Sale/PaymentCalculator.php',
        StoreStockFilter::class => 'lib/Sale/StoreStockFilter.php',
        OrderCreator::class => 'lib/Sale/OrderCreator.php',
        OrderHistory::class => 'lib/Sale/OrderHistory.php',
        OrderStatusSync::class => 'lib/Sale/OrderStatusSync.php',
        StoreMap::class => 'lib/Sale/StoreMap.php',
        OrderBuyer::class => 'lib/User/OrderBuyer.php',
        PhoneCode::class => 'lib/User/PhoneCode.php',
        PhoneUserFinder::class => 'lib/User/PhoneUserFinder.php',
        PhoneOtp::class => 'lib/User/PhoneOtp.php',
        UserAccount::class => 'lib/User/UserAccount.php',
        UserProfile::class => 'lib/User/UserProfile.php',
        OtpConfirmWebhook::class => 'lib/Webhook/OtpConfirmWebhook.php',
        OtpRequestWebhook::class => 'lib/Webhook/OtpRequestWebhook.php',
        UserGetWebhook::class => 'lib/Webhook/UserGetWebhook.php',
        UserOtpConfirmWebhook::class => 'lib/Webhook/UserOtpConfirmWebhook.php',
        UserOtpRequestWebhook::class => 'lib/Webhook/UserOtpRequestWebhook.php',
        UserUpdateWebhook::class => 'lib/Webhook/UserUpdateWebhook.php',
    ]
);

if (Option::get('bx.imshop.integration', 'status_map_dropped', 'N') !== 'Y') {
    $connection = Application::getConnection();
    if ($connection->isTableExists('b_bx_imshop_order_status')) {
        $connection->dropTable('b_bx_imshop_order_status');
    }
    Option::set('bx.imshop.integration', 'status_map_dropped', 'Y');
}
