# rasuvaeff/yii3-outbox-webhooks-bridge
[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-outbox-webhooks-bridge.svg)](https://packagist.org/packages/rasuvaeff/yii3-outbox-webhooks-bridge)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-outbox-webhooks-bridge.svg)](https://packagist.org/packages/rasuvaeff/yii3-outbox-webhooks-bridge)
[![Build](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/static-analysis.yml)
[![Psalm level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-webhooks-bridge/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-webhooks-bridge)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
Мосты `yii3-outbox` и `yii3-webhooks` для надежной доставки веб-перехватчика хотя бы один раз. Каждое исходящее сообщение преобразуется в WebhookEvent и отправляется настроенным конечным точкам через внедренный WebhookDispatcher.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактный справочник API, предназначенный для LLM. @@ЛИНИЯ@@
## Требования
- PHP 8.3–8.5
 - `rasuvaeff/yii3-outbox` ^1.0
 - `rasuvaeff/yii3-webhooks` ^1.0
 - Реализация `WebhookDispatcher` (например, адаптер на основе PSR-18 в вашем приложении)
 - Реализация `WebhookDeliveryStorage` (например, `yii3-webhooks-db`)

## Установка
```bash
composer require rasuvaeff/yii3-outbox-webhooks-bridge
```
## Использование
### 1. Настройте конечные точки
```php
use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;

$endpointProvider = new ConfigWebhookEndpointProvider(map: [
    'order.created' => [
        new WebhookEndpoint(url: 'https://partner-a.example.com/hooks', secret: 'secret-a'),
        new WebhookEndpoint(url: 'https://partner-b.example.com/hooks', secret: 'secret-b'),
    ],
    'order.paid' => [
        new WebhookEndpoint(url: 'https://partner-a.example.com/hooks', secret: 'secret-a'),
    ],
]);
```
### 2. Свяжитесь с издателем
```php
use Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher;

$publisher = new OutboxWebhookPublisher(
    dispatcher: $dispatcher,        // your WebhookDispatcher impl
    endpointProvider: $endpointProvider,
    deliveryStorage: $deliveryStorage, // e.g. DbWebhookDeliveryStorage
);
```
### 3. Запускаем обработчик исходящих сообщений
```php
use Rasuvaeff\Yii3Outbox\Processor;

$processor = new Processor(
    storage: $outboxStorage,
    publisher: $publisher,
    clock: $clock,
);

// In a background worker or console command:
$result = $processor->process(types: ['order.created', 'order.paid']);
```
### Поведение
| Ситуация | Результат |
 |---|---|
 | Конечная точка возвращает «Доставлено» | Доставка сохранена; сообщение отмечено как опубликованное |
 | Конечная точка возвращает `Failed` | Доставка сохранена; Выброшено `PublishException` → повторные попытки исходящих сообщений |
 | Диспетчер бросает | Выброшено `PublishException` → повторные попытки исходящих сообщений |
 | Нет конечных точек для типа | Тихий успех (нулевые поставки, сообщение опубликовано) |
 | Несколько конечных точек, одна не работает | Все отправлено; Выброшено `PublishException` → все попытки повторены | @@ЛИНИЯ@@
### Дедупликация идентификатора события
Идентификатор исходящего сообщения повторно используется как идентификатор WebhookEvent. При повторной попытке тот же идентификатор
 отправляется снова. Получатели должны использовать заголовок X-Webhook-Id (устанавливаемый
 `HmacSha256Signer`) для идемпотентности. @@ЛИНИЯ@@
### Пользовательский поставщик конечных точек
Внедрите WebhookEndpointProvider для загрузки конечных точек из базы данных, кэша или
 любого источника времени выполнения:

```php
use Rasuvaeff\Yii3OutboxWebhooksBridge\WebhookEndpointProvider;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;

final readonly class DbWebhookEndpointProvider implements WebhookEndpointProvider
{
    public function __construct(private \PDO $db) {}

    public function getEndpointsForType(string $type): array
    {
        // load from DB...
    }
}
```
## Безопасность
— Секреты никогда не хранятся в `WebhookDelivery` (происходит от `yii3-webhooks`).
 — используйте `HmacSha256Signer` (из `yii3-webhooks`) в качестве подписывающего устройства
 вашего `WebhookDispatcher` для аутентификации исходящих запросов.
 — Получатели должны проверить подпись через `WebhookVerifier` и использовать
 `ReplayGuard` для предотвращения повтора nonce. @@ЛИНИЯ@@
## Примеры
См. [`examples/`](examples/) для ознакомления с работоспособными скриптами. @@ЛИНИЯ@@
## Разработка
```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
