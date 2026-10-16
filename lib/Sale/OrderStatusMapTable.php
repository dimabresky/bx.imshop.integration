<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * IMSHOP order status mapped to a Bitrix order status.
 */
class OrderStatusMapTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_bx_imshop_order_status';
    }

    public static function getMap(): array
    {
        return [
            (new StringField('IMSHOP_CODE'))
                ->configurePrimary(true)
                ->configureRequired(true)
                ->configureSize(32)
                ->addValidator(new LengthValidator(1, 32)),
            (new StringField('STATUS_ID'))
                ->configureRequired(true)
                ->configureSize(2)
                ->addValidator(new LengthValidator(1, 2)),
            (new StringField('MESSAGE'))
                ->configureSize(500)
                ->configureDefaultValue('')
                ->addValidator(new LengthValidator(null, 500)),
        ];
    }

    /**
     * shortcut: create on first use, installDB does not run again on an already installed module.
     */
    public static function ensure(): void
    {
        $connection = Application::getConnection();
        if ($connection->isTableExists(static::getTableName())) {
            return;
        }

        static::getEntity()->createDbTable();
    }
}
