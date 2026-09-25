<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

/**
 * Installer for local module bx.imshop.integration.
 */
class bx_imshop_integration extends CModule
{
    /** @var string */
    public $MODULE_ID = 'bx.imshop.integration';

    /** @var string */
    public $MODULE_VERSION;

    /** @var string */
    public $MODULE_VERSION_DATE;

    /** @var string */
    public $MODULE_NAME;

    /** @var string */
    public $MODULE_DESCRIPTION;

    /** @var string */
    public $PARTNER_NAME;

    /** @var string */
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

        $this->MODULE_NAME = Loc::getMessage('BX_IMSHOP_INTEGRATION_MODULE_NAME') ?: 'IMSHOP webhooks';
        $this->MODULE_DESCRIPTION = Loc::getMessage('BX_IMSHOP_INTEGRATION_MODULE_DESC')
            ?: '1C-Bitrix online store integration with IMSHOP Retail Protocol.';
        $this->PARTNER_NAME = Loc::getMessage('BX_IMSHOP_INTEGRATION_PARTNER_NAME') ?: 'DNK';
        $this->PARTNER_URI = Loc::getMessage('BX_IMSHOP_INTEGRATION_PARTNER_URI') ?: 'https://dnk.by';
    }

    public function DoInstall(): bool
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->installDB();
        $this->installEvents();
        $this->installFiles();

        return true;
    }

    public function DoUninstall(): bool
    {
        $this->unInstallFiles();
        $this->unInstallEvents();
        $this->unInstallDB();
        Option::delete($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    public function installDB(): bool
    {
        return true;
    }

    public function unInstallDB(): bool
    {
        return true;
    }

    public function installFiles(): bool
    {
        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot === '') {
            return false;
        }

        CopyDirFiles(
            __DIR__ . '/imshop',
            $documentRoot . '/local/imshop',
            true,
            true
        );

        return true;
    }

    public function unInstallFiles(): bool
    {
        DeleteDirFilesEx('/local/imshop');

        return true;
    }

    public function installEvents(): void
    {
    }

    public function unInstallEvents(): void
    {
    }
}
