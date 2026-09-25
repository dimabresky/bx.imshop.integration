# AGENTS.md — модуль bx.imshop.integration

Инструкции для AI-агентов, которые пишут и поддерживают локальный модуль webhook-ов IMSHOP для 1C-Bitrix.
Всегда следуй этим правилам, если пользователь явно не попросил иное.

## Цель и контекст

- Модуль `bx.imshop.integration` принимает webhook-и [IMSHOP Retail Protocol](https://docs.imshop.io/) от платформы IMSHOP и отвечает JSON.
- Запросы stateless: каждый POST содержит все данные для ответа. Сессия, cookies и корзина `fuser` не источник данных.
- Расчёт доставки повторяет последовательность `bitrix:sale.order.ajax` (`initShipment`, `getRestrictedObjectsList`, `calculateDelivery`, `doFinalAction`, склады из `obtainDelivery`) на временном заказе.
- Временный `Bitrix\Sale\Order` не сохраняется. Вызов `Order::save()` в этом модуле запрещён.
- Точка входа на сайте — каталог `/local/imshop/<code>/`. Его копирует `installFiles()` из `install/imshop/` и удаляет `unInstallFiles()`. Роутер модуля — `public/endpoint.php`.
- Секреты (ключ API) только в опциях модуля на стенде, не в репозитории.
- Ответ webhook не содержит полей заказа, которых нет в контракте IMSHOP для этого endpoint.

## Структура модуля

```text
bx.imshop.integration/
  include.php
  options.php
  default_option.php
  public/endpoint.php
  install/imshop/
  lib/
    Config.php
    Http/
    Webhook/
    Sale/
  install/index.php
  lang/ru/
```

- Классы — в `lib/`, namespace `Bx\Imshop\Integration`.
- Новый webhook = класс `WebhookHandlerInterface` в `lib/Webhook/`, регистрация в `Registry`, каталог `install/imshop/<code>/` и строка в `README.md`. Установка копирует каталог в `/local/imshop/`.
- `install/index.php` — установка и удаление. Своих таблиц у модуля нет.

## PSR и стиль кода

- PSR-12, PSR-4 (карта в `include.php`), PSR-1.
- Классы — `PascalCase`, методы — `camelCase`, константы — `UPPER_CASE`.
- Один класс или интерфейс — один файл.

## SOLID

- **S** — HTTP только принимает JSON и вызывает обработчик. Расчёт доставки, поиск локации и маппинг ответа — отдельные классы.
- **O** — следующий endpoint добавляется новым обработчиком, без переписывания `FrontController`.
- **L** — обработчики взаимозаменяемы через `WebhookHandlerInterface`.
- **I** — интерфейс webhook содержит только `code()` и `handle()`.
- **D** — обработчик зависит от сервиса расчёта, а не от `$_POST` и не от компонента оформления заказа.

## DRY

- Один разбор JSON, одна проверка ключа, один способ собрать временный заказ.
- Не копировать класс `SaleOrderAjax`. Повторять его вызовы Sale API.

## База данных и Sale

- ORM D7 и API Sale/Catalog. Свои таблицы не заводить, пока спецификация webhook этого не требует.
- Местоположение в свойство заказа с `IS_LOCATION` передаётся кодом справочника, не названием города.
- Купон запроса — `DiscountCouponsManager` в `MODE_EXTERNAL` на время одного запроса, затем `clear(true)`.
- `bonusesSpent` в расчёт доставки не входит: списание бонусов Аспро привязано к сессии чекаута.

## Установка и настройки

- `DoInstall` / `DoUninstall`, `installDB` / `unInstallDB` / `installFiles` / `unInstallFiles` — `public`, как в `CModule`.
- Опции: ключ API, сайт, типы плательщика, флаг включения, опциональный журнал. Пустой ключ — ответ 401.
- `installFiles()` копирует `install/imshop/` в `/local/imshop/`. `unInstallFiles()` удаляет только `/local/imshop/`. После деплоя git URL появляется, когда модуль установлен.

## Безопасность

- Проверять ключ до бизнес-логики. Сравнивать через `hash_equals`.
- При включённом журнале писать тело запроса и ответ в `logs/bx.imshop.integration/` рядом с корнем сайта, вне публичного каталога. Неожиданные ошибки писать туда всегда. Заголовки и ключ API писать только если включена опция «Писать заголовки и секреты».
- Неизвестные позиции каталога пропускать, а не подставлять чужой товар.

## Git и delivery

Репозиторий модуля — отдельный git-корень. Origin: `https://github.com/dimabresky/bx.imshop.integration.git`.

| Ветка | Назначение |
|-------|------------|
| `main` | production / стабильный код |
| `dev` | интеграционная ветка |
| `feat/…`, `fix/…` | рабочие ветки от `dev` |

### Обязательный флоу

1. Проверь `git status` и текущую ветку.
2. Убедись, что ветка `dev` существует локально или на remote.
3. `git fetch origin`.
4. Создай feature-ветку от latest `dev` (`feat/<topic>` или `fix/<topic>`).
5. Реализуй только запрошенные изменения.
6. Прогони релевантные проверки.
7. Закоммить на английском по [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).
8. Запусти `/review-bugbot` по изменениям ветки относительно `dev` и исправь findings (max **4** итерации на finding). Не push и не создавай PR, пока этот шаг не завершён.
9. Push ветки и создай PR только в `dev` после чистого Bugbot на latest commit (или явного разрешения пользователя по оставшимся findings).
10. Babysit PR: валидные review-комментарии, CI failures этого PR, merge conflicts при ясном intent; иначе спроси пользователя.
11. Когда PR green/mergeable и комментарии закрыты — спроси явное подтверждение перед merge.
12. После подтверждения — merge PR.
13. Удали remote feature-ветку только после успешного merge.

### Правила безопасности флоу

- Никогда не создавай PR, если Bugbot не был запущен после latest commit на feature-ветке.
- Не превышай 4 fix+re-review итерации на один finding.
- После новых коммитов после Bugbot — перезапусти `/review-bugbot` перед созданием или обновлением PR.
- Не мержи в `dev` при падающем CI, отсутствующих required reviews или unresolved comments.
- Не force-push без явной просьбы пользователя.
- Не удаляй remote-ветку до подтверждения merge.
- Если `dev` отсутствует — остановись и спроси базовую ветку.
- Несвязанные локальные изменения не перезаписывай; спроси, как поступить.

### Bugbot gate

После latest commit и до `git push` / `gh pr create` запусти Bugbot:

```text
Full Repository Path: <absolute repository path>
Diff: branch changes
Base Branch: dev
```

Если findings есть — исправь и перезапусти review (в лимите 4 итераций). Если Bugbot чист — открывай PR в `dev`.
