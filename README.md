# rasuvaeff/yii3-outbox-webhooks-bridge

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-outbox-webhooks-bridge.svg)](https://packagist.org/packages/rasuvaeff/yii3-outbox-webhooks-bridge)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-outbox-webhooks-bridge.svg)](https://packagist.org/packages/rasuvaeff/yii3-outbox-webhooks-bridge)
[![Build](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/actions/workflows/static-analysis.yml)
[![Psalm level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-webhooks-bridge/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-webhooks-bridge)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Bridges `yii3-outbox` and `yii3-webhooks` for durable at-least-once webhook delivery. Each outbox message is converted to a `WebhookEvent` and dispatched to configured endpoints via an injected `WebhookDispatcher`.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference designed for LLMs.

## Requirements

- PHP 8.3–8.5
- `rasuvaeff/yii3-outbox` ^1.0
- `rasuvaeff/yii3-webhooks` ^1.0
- A `WebhookDispatcher` implementation (e.g. a PSR-18-based adapter in your app)
- A `WebhookDeliveryStorage` implementation (e.g. `yii3-webhooks-db`)

## Installation

```bash
composer require rasuvaeff/yii3-outbox-webhooks-bridge
```

## Usage

### 1. Configure endpoints

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

### 2. Wire the publisher

```php
use Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher;

$publisher = new OutboxWebhookPublisher(
    dispatcher: $dispatcher,        // your WebhookDispatcher impl
    endpointProvider: $endpointProvider,
    deliveryStorage: $deliveryStorage, // e.g. DbWebhookDeliveryStorage
);
```

### 3. Run the outbox processor

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

### Behaviour

| Situation | Result |
|---|---|
| Endpoint returns `Delivered` | Delivery saved; message marked published |
| Endpoint returns `Failed` | Delivery saved; `PublishException` thrown → outbox retries |
| Dispatcher throws | `PublishException` thrown → outbox retries |
| No endpoints for type | Silent success (zero deliveries, message published) |
| Multiple endpoints, one fails | All dispatched; `PublishException` thrown → all retried |

### Event id dedup

The outbox message id is reused as the `WebhookEvent` id. On retry, the same id
is sent again. Receivers should use the `X-Webhook-Id` header (set by
`HmacSha256Signer`) for idempotency.

### Custom endpoint provider

Implement `WebhookEndpointProvider` to load endpoints from a database, cache, or
any runtime source:

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

## Security

- Secrets are never stored in `WebhookDelivery` (comes from `yii3-webhooks`).
- Use `HmacSha256Signer` (from `yii3-webhooks`) as your `WebhookDispatcher`'s
  signer to authenticate outbound requests.
- Receivers should validate the signature via `WebhookVerifier` and use
  `ReplayGuard` against nonce replay.

## Examples

See [`examples/`](examples/) for runnable scripts.

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
