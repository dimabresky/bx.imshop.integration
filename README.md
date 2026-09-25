# bx.imshop.integration

Webhook-и IMSHOP Retail Protocol для интернет-магазина 1C-Bitrix.

Origin: `https://github.com/dimabresky/bx.imshop.integration.git`.

Модуль размещается в `local/modules/bx.imshop.integration` и может подключаться как git submodule. Установка копирует `install/imshop/` в `/local/imshop/`. Удаление модуля удаляет этот каталог.

Ключ передаётся заголовком `Authorization: Bearer <key>` или полем `key` в JSON. Ключ задаётся в настройках модуля после установки и в репозиторий не попадает. Пустой ключ закрывает endpoint.

## Реализованные webhooks

Список пополняется при добавлении endpoint.

- `POST /local/imshop/deliveries` — расчёт способов доставки. Временный заказ Sale не сохраняется. Цена и пункты самовывоза совпадают с `bitrix:sale.order.ajax`.
- `POST /local/imshop/payments` — список способов оплаты. Временный заказ Sale не сохраняется. Состав совпадает с ограничениями `bitrix:sale.order.ajax` для выбранной доставки.
