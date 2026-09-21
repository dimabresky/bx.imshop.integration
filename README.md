# bx.imshop.integration

Webhook-и IMSHOP Retail Protocol для интернет-магазина 1C-Bitrix.

Origin: `https://github.com/dimabresky/bx.imshop.integration.git`.

В сайте DNK модуль подключён submodule в `local/modules/bx.imshop.integration`. Публичный URL первой итерации:

`POST /local/imshop/deliveries`

Ключ передаётся заголовком `Authorization: Bearer <key>` или полем `key` в JSON. Ключ задаётся в настройках модуля после установки и в репозиторий не попадает. Пустой ключ закрывает endpoint.

Расчёт доставки строится на временном заказе Sale и не сохраняет его. Список служб, цена и пункты самовывоза совпадают с логикой `bitrix:sale.order.ajax`.
