<?php

/**
 * Admin settings page for bx.imshop.integration.
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bx\Imshop\Integration\Config;

global $APPLICATION;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$mid = 'bx.imshop.integration';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($mid);
if ($MODULE_RIGHT < 'R') {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

if (!Loader::includeModule($mid)) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

$actionMessage = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $MODULE_RIGHT >= 'W'
    && check_bitrix_sessid()
    && !empty($_REQUEST['Update'])
) {
    Option::set($mid, 'enabled', !empty($_REQUEST['enabled']) ? 'Y' : 'N');
    Option::set($mid, 'api_key', trim((string) ($_REQUEST['api_key'] ?? '')));
    Option::set($mid, 'site_id', trim((string) ($_REQUEST['site_id'] ?? '')));
    Option::set($mid, 'person_type_id', (string) max(0, (int) ($_REQUEST['person_type_id'] ?? 0)));
    Option::set($mid, 'person_type_legal_id', (string) max(0, (int) ($_REQUEST['person_type_legal_id'] ?? 0)));
    Option::set($mid, 'logging', !empty($_REQUEST['logging']) ? 'Y' : 'N');
    Option::set($mid, 'logging_secrets', !empty($_REQUEST['logging_secrets']) ? 'Y' : 'N');
    if (Loader::includeModule('sale')) {
        Option::set($mid, 'pickup_delivery_ids', implode(',', pickupDeliveryIdsFromRequest()));
    }
    $actionMessage = (string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_SAVED');
}

$enabled = Option::get($mid, 'enabled', 'Y') === 'Y';
$apiKey = (string) Option::get($mid, 'api_key', '');
$siteId = (string) Option::get($mid, 'site_id', '');
$personTypeId = (string) Option::get($mid, 'person_type_id', '0');
$personTypeLegalId = (string) Option::get($mid, 'person_type_legal_id', '0');
$logging = Option::get($mid, 'logging', 'N') === 'Y';
$loggingSecrets = Option::get($mid, 'logging_secrets', 'N') === 'Y';
$pickupDeliveryIds = array_fill_keys(Config::pickupDeliveryIds(), true);
$deliveryServices = activeDeliveryServices();

$APPLICATION->SetTitle((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($actionMessage !== '') {
    CAdminMessage::ShowMessage([
        'MESSAGE' => $actionMessage,
        'TYPE' => 'OK',
    ]);
}
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($mid) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <table class="adm-detail-content-table edit-table">
        <tr>
            <td width="40%"><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_ENABLED')) ?></td>
            <td><input type="checkbox" name="enabled" value="Y"<?= $enabled ? ' checked' : '' ?>></td>
        </tr>
        <tr>
            <td><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_API_KEY')) ?></td>
            <td>
                <input type="password" name="api_key" value="<?= htmlspecialcharsbx($apiKey) ?>" size="50" autocomplete="off">
                <div class="adm-info-message-wrap" style="margin-top:8px;">
                    <?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_API_KEY_HINT')) ?>
                </div>
            </td>
        </tr>
        <tr>
            <td><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_SITE_ID')) ?></td>
            <td>
                <input type="text" name="site_id" value="<?= htmlspecialcharsbx($siteId) ?>" size="10">
                <div><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_SITE_ID_HINT')) ?></div>
            </td>
        </tr>
        <tr>
            <td><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_PERSON_TYPE')) ?></td>
            <td>
                <input type="number" min="0" name="person_type_id" value="<?= htmlspecialcharsbx($personTypeId) ?>">
                <div><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_PERSON_TYPE_HINT')) ?></div>
            </td>
        </tr>
        <tr>
            <td><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_PERSON_TYPE_LEGAL')) ?></td>
            <td>
                <input type="number" min="0" name="person_type_legal_id" value="<?= htmlspecialcharsbx($personTypeLegalId) ?>">
                <div><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_PERSON_TYPE_LEGAL_HINT')) ?></div>
            </td>
        </tr>
        <tr>
            <td><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_PICKUP_DELIVERIES')) ?></td>
            <td>
                <select name="pickup_delivery_ids[]" multiple size="8">
                    <?php foreach ($deliveryServices as $deliveryId => $deliveryName) { ?>
                        <option value="<?= (int) $deliveryId ?>"<?= isset($pickupDeliveryIds[$deliveryId]) ? ' selected' : '' ?>>
                            <?= htmlspecialcharsbx($deliveryId . ' — ' . $deliveryName) ?>
                        </option>
                    <?php } ?>
                </select>
                <div><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_PICKUP_DELIVERIES_HINT')) ?></div>
            </td>
        </tr>
        <tr>
            <td><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_LOGGING')) ?></td>
            <td>
                <input type="checkbox" name="logging" value="Y"<?= $logging ? ' checked' : '' ?>>
                <div><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_LOGGING_HINT')) ?></div>
            </td>
        </tr>
        <tr>
            <td><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_LOGGING_SECRETS')) ?></td>
            <td>
                <input type="checkbox" name="logging_secrets" value="Y"<?= $loggingSecrets ? ' checked' : '' ?>>
                <div><?= htmlspecialcharsbx((string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_LOGGING_SECRETS_HINT')) ?></div>
            </td>
        </tr>
    </table>
    <input type="submit" name="Update" value="<?= htmlspecialcharsbx((string) Loc::getMessage('MAIN_SAVE')) ?>" class="adm-btn-save">
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

/**
 * @return list<int>
 */
function pickupDeliveryIdsFromRequest(): array
{
    $picked = $_REQUEST['pickup_delivery_ids'] ?? [];
    if (!is_array($picked)) {
        return [];
    }

    $ids = [];
    foreach ($picked as $id) {
        if (is_numeric($id) && (int) $id > 0) {
            $ids[] = (int) $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @return array<int, string>
 */
function activeDeliveryServices(): array
{
    if (!Loader::includeModule('sale')) {
        return [];
    }

    $services = [];
    $rows = \Bitrix\Sale\Delivery\Services\Table::getList([
        'select' => ['ID', 'NAME'],
        'filter' => ['=ACTIVE' => 'Y'],
        'order' => ['SORT' => 'ASC', 'NAME' => 'ASC'],
    ]);
    while ($row = $rows->fetch()) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $services[$id] = trim((string) ($row['NAME'] ?? ''));
    }

    return $services;
}
