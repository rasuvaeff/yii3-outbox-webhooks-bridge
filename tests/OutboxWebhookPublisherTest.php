<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge\Tests;

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3OutboxWebhooksBridge\OutboxWebhookPublisher;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OutboxWebhookPublisher::class)]
final class OutboxWebhookPublisherTest
{
    private InMemoryDeliveryStorage $storage;
    private WebhookEndpoint $endpoint;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryDeliveryStorage();
        $this->endpoint = new WebhookEndpoint(url: 'https://example.com/hooks', secret: 'secret');
    }

    public function succeedsWhenDispatcherReturnsDeliveredDelivery(): void
    {
        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
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

        Assert::count($this->storage, 1);
    }

    public function succeedsSilentlyWhenNoEndpointsConfigured(): void
    {
        $dispatcher = new FakeWebhookDispatcher();

        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: new ConfigWebhookEndpointProvider(),
            deliveryStorage: $this->storage,
        );

        $publisher->publish($this->makeMessage(type: 'order.created'));

        Assert::count($this->storage, 0);
    }

    public function throwsPublishExceptionWhenDeliveryStatusIsFailed(): void
    {
        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
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
            Assert::fail('Expected PublishException');
        } catch (PublishException) {
        }
    }

    public function throwsPublishExceptionWhenDispatcherThrows(): void
    {
        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(): never => throw new \RuntimeException('Connection refused'),
        );

        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$this->endpoint]]);
        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: $provider,
            deliveryStorage: $this->storage,
        );

        try {
            $publisher->publish($this->makeMessage(type: 'order.created'));
            Assert::fail('Expected PublishException');
        } catch (PublishException $e) {
            Assert::string($e->getMessage())->contains('Connection refused');
        }
    }

    public function includesUnknownErrorInMessageWhenLastErrorIsNull(): void
    {
        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
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
            Assert::fail('Expected PublishException');
        } catch (PublishException $e) {
            Assert::string($e->getMessage())->contains('unknown error');
        }
    }

    public function includesActualLastErrorInMessageWhenSet(): void
    {
        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
                => WebhookDelivery::create(event: $event, endpoint: $endpoint)
                    ->withAttempt(new DateTimeImmutable(), 'HTTP 503 Service Unavailable')
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
            Assert::fail('Expected PublishException');
        } catch (PublishException $e) {
            Assert::string($e->getMessage())->contains('HTTP 503 Service Unavailable');
        }
    }

    public function savesDeliveryBeforeThrowingOnFailedStatus(): void
    {
        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
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

        Assert::count($this->storage, 1);
    }

    public function dispatchesToAllEndpointsAndCollectsFailures(): void
    {
        $ep2 = new WebhookEndpoint(url: 'https://b.example.com/hook', secret: 'secret-b');

        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
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

        Assert::count($this->storage, 2);
    }

    public function convertsMessageIdToEventId(): void
    {
        $capturedEvent = null;
        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
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

        Assert::notNull($capturedEvent);
        Assert::same($capturedEvent->getId(), $message->getId());
        Assert::same($capturedEvent->getType(), 'order.created');
        Assert::same($capturedEvent->getPayload(), '{"key":"value"}');
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
