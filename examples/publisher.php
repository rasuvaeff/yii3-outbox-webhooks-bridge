<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

// Stub dispatcher that records calls and marks everything Delivered.
$dispatcher = new class implements WebhookDispatcher {
    public function dispatch(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
    {
        printf("  -> dispatching %s to %s%s", $event->getType(), $endpoint->getUrl(), PHP_EOL);

        return WebhookDelivery::create(event: $event, endpoint: $endpoint)
            ->withStatus(WebhookDeliveryStatus::Delivered);
    }
};

$storage = new InMemoryDeliveryStorage();

$provider = new ConfigWebhookEndpointProvider(map: [
    'order.created' => [
        new WebhookEndpoint(url: 'https://partner-a.example.com/hooks', secret: 'secret-a'),
        new WebhookEndpoint(url: 'https://partner-b.example.com/hooks', secret: 'secret-b'),
    ],
]);

$publisher = new OutboxWebhookPublisher(
    dispatcher: $dispatcher,
    endpointProvider: $provider,
    deliveryStorage: $storage,
);

$message = OutboxMessage::create(
    type: 'order.created',
    payload: json_encode(['orderId' => 'ord-123', 'total' => 99.99], JSON_THROW_ON_ERROR),
    createdAt: new DateTimeImmutable(),
);

echo 'Publishing message: ' . $message->getType() . PHP_EOL;

$publisher->publish($message);

echo sprintf('Done. %d delivery record(s) saved.%s', count($storage), PHP_EOL);
