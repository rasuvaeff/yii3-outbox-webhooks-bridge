# rasuvaeff/yii3-outbox-webhooks-bridge

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-outbox-webhooks-bridge.svg)](https://packagist.org/packages/rasuvaeff/yii3-outbox-webhooks-bridge)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-outbox-webhooks-bridge.svg)](https://packagist.org/packages/rasuvaeff/yii3-outbox-webhooks-bridge)
[![Build](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/static-analysis.yml)
[![Psalm level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-webhooks-bridge/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-webhooks-bridge)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Связывает `yii3-outbox` и `yii3-webhooks` для надёжной доставки webhook-ов с
семантикой at-least-once. Каждое outbox-сообщение преобразуется в
`WebhookEvent` и отправляется на настроенные эндпоинты через инжектируемый
`WebhookDispatcher`.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник для LLM.

## Требования

- PHP 8.3–8.5
- `rasuvaeff/yii3-outbox` ^1.0
- `rasuvaeff/yii3-webhooks` ^1.0
- Реализация `WebhookDispatcher` (например, PSR-18-адаптер в вашем приложении)
- Реализация `WebhookDeliveryStorage` (например, `yii3-webhooks-db`)

## Установка

```bash
composer require rasuvaeff/yii3-outbox-webhooks-bridge
```

## Использование

### 1. Конфигурация эндпоинтов

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

### 2. Подключение publisher-а

```php
use Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher;

$publisher = new OutboxWebhookPublisher(
    dispatcher: $dispatcher,        // your WebhookDispatcher impl
    endpointProvider: $endpointProvider,
    deliveryStorage: $deliveryStorage, // e.g. DbWebhookDeliveryStorage
);
```

### 3. Запуск процессора outbox

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
| Эндпоинт возвращает `Delivered` | Доставка сохранена; сообщение помечено как опубликованное |
| Эндпоинт возвращает `Failed` | Доставка сохранена; бросается `PublishException` → outbox делает retry |
| Dispatcher бросает исключение | Бросается `PublishException` → outbox делает retry |
| Для типа нет эндпоинтов | Тихий успех (ноль доставок, сообщение опубликовано) |
| Несколько эндпоинтов, один упал | Все получили отправку; бросается `PublishException` → retry по всем |

### Дедупликация по id события

Id outbox-сообщения переиспользуется как id `WebhookEvent`. При retry тот же id
отправляется снова. Получатели должны использовать заголовок `X-Webhook-Id`
(устанавливается `HmacSha256Signer`) для идемпотентности.

### Собственный провайдер эндпоинтов

Реализуйте `WebhookEndpointProvider`, чтобы загружать эндпоинты из БД, кэша или
любого runtime-источника:

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

- Секреты никогда не хранятся в `WebhookDelivery` (это часть `yii3-webhooks`).
- Используйте `HmacSha256Signer` (из `yii3-webhooks`) как signer вашего
  `WebhookDispatcher` для аутентификации исходящих запросов.
- Получатели должны проверять подпись через `WebhookVerifier` и использовать
  `ReplayGuard` для защиты от повторного использования nonce.

## Примеры

См. [`examples/`](examples/) — запускаемые скрипты.

## Разработка

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
