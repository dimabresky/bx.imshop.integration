<?php

/**
 * Admin settings page for bx.imshop.integration.
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

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
    $actionMessage = (string) Loc::getMessage('BX_IMSHOP_INTEGRATION_OPTIONS_SAVED');
}

$enabled = Option::get($mid, 'enabled', 'Y') === 'Y';
$apiKey = (string) Option::get($mid, 'api_key', '');
$siteId = (string) Option::get($mid, 'site_id', '');
$personTypeId = (string) Option::get($mid, 'person_type_id', '0');
$personTypeLegalId = (string) Option::get($mid, 'person_type_legal_id', '0');

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
    </table>
    <input type="submit" name="Update" value="<?= htmlspecialcharsbx((string) Loc::getMessage('MAIN_SAVE')) ?>" class="adm-btn-save">
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
