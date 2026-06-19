<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

#[CoversClass(OutboxWebhookPublisher::class)]
final class OutboxWebhookPublisherTest extends TestCase
{
    private InMemoryDeliveryStorage $storage;
    private WebhookEndpoint $endpoint;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryDeliveryStorage();
        $this->endpoint = new WebhookEndpoint(url: 'https://example.com/hooks', secret: 'secret');
    }

    #[Test]
    public function succeedsWhenDispatcherReturnsDeliveredDelivery(): void
    {
        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
                => WebhookDelivery::create(event: $event, endpoint: $endpoint)
                    ->withStatus(WebhookDeliveryStatus::Delivered),
        );

        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$this->endpoint]]);
        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: $provider,
            deliveryStorage: $this->storage,
        );

        $publisher->publish($this->makeMessage(type: 'order.created'));

        $this->assertCount(1, $this->storage);
    }

    #[Test]
    public function succeedsSilentlyWhenNoEndpointsConfigured(): void
    {
        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: new ConfigWebhookEndpointProvider(),
            deliveryStorage: $this->storage,
        );

        $publisher->publish($this->makeMessage(type: 'order.created'));

        $this->assertCount(0, $this->storage);
    }

    #[Test]
    public function throwsPublishExceptionWhenDeliveryStatusIsFailed(): void
    {
        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
                => WebhookDelivery::create(event: $event, endpoint: $endpoint)
                    ->withStatus(WebhookDeliveryStatus::Failed),
        );

        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$this->endpoint]]);
        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: $provider,
            deliveryStorage: $this->storage,
        );

        $this->expectException(PublishException::class);

        $publisher->publish($this->makeMessage(type: 'order.created'));
    }

    #[Test]
    public function throwsPublishExceptionWhenDispatcherThrows(): void
    {
        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Connection refused'));

        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$this->endpoint]]);
        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: $provider,
            deliveryStorage: $this->storage,
        );

        $this->expectException(PublishException::class);
        $this->expectExceptionMessage('Connection refused');

        $publisher->publish($this->makeMessage(type: 'order.created'));
    }

    #[Test]
    public function savesDeliveryBeforeThrowingOnFailedStatus(): void
    {
        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
                => WebhookDelivery::create(event: $event, endpoint: $endpoint)
                    ->withStatus(WebhookDeliveryStatus::Failed),
        );

        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$this->endpoint]]);
        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: $provider,
            deliveryStorage: $this->storage,
        );

        try {
            $publisher->publish($this->makeMessage(type: 'order.created'));
        } catch (PublishException) {
        }

        $this->assertCount(1, $this->storage);
    }

    #[Test]
    public function dispatchesToAllEndpointsAndCollectsFailures(): void
    {
        $ep2 = new WebhookEndpoint(url: 'https://b.example.com/hook', secret: 'secret-b');

        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
                => WebhookDelivery::create(event: $event, endpoint: $endpoint)
                    ->withStatus(WebhookDeliveryStatus::Delivered),
        );

        $provider = new ConfigWebhookEndpointProvider(map: [
            'order.created' => [$this->endpoint, $ep2],
        ]);
        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: $provider,
            deliveryStorage: $this->storage,
        );

        $publisher->publish($this->makeMessage(type: 'order.created'));

        $this->assertCount(2, $this->storage);
    }

    #[Test]
    public function convertsMessageIdToEventId(): void
    {
        $capturedEvent = null;
        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function (WebhookEvent $event, WebhookEndpoint $endpoint) use (&$capturedEvent): WebhookDelivery {
                $capturedEvent = $event;

                return WebhookDelivery::create(event: $event, endpoint: $endpoint)
                    ->withStatus(WebhookDeliveryStatus::Delivered);
            },
        );

        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$this->endpoint]]);
        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: $provider,
            deliveryStorage: $this->storage,
        );

        $message = $this->makeMessage(type: 'order.created');
        $publisher->publish($message);

        $this->assertNotNull($capturedEvent);
        $this->assertSame($message->getId(), $capturedEvent->getId());
        $this->assertSame('order.created', $capturedEvent->getType());
        $this->assertSame('{"key":"value"}', $capturedEvent->getPayload());
    }

    private function makeMessage(string $type): OutboxMessage
    {
        return OutboxMessage::create(
            type: $type,
            payload: '{"key":"value"}',
            createdAt: new DateTimeImmutable('2026-06-19 12:00:00'),
        );
    }
}
