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
        } catch (PublishException $e) {
            Assert::string($e->getMessage())->contains('Webhook delivery failed for 1 endpoint(s)');
            Assert::same($e->getOutboxMessage()->getType(), 'order.created');
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

    public function masksTheEndpointQueryStringInTheFailureMessage(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://hooks.example.com/e?access_token=s3cret-token', secret: 'secret');

        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
                => WebhookDelivery::create(event: $event, endpoint: $endpoint)
                    ->withAttempt(new DateTimeImmutable(), 'HTTP 500')
                    ->withStatus(WebhookDeliveryStatus::Failed),
        );

        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: new ConfigWebhookEndpointProvider(map: ['order.created' => [$endpoint]]),
            deliveryStorage: $this->storage,
        );

        try {
            $publisher->publish($this->makeMessage(type: 'order.created'));
            Assert::fail('Expected PublishException');
        } catch (PublishException $e) {
            Assert::string($e->getMessage())
                ->contains('https://hooks.example.com/e?access_token=***')
                ->contains('HTTP 500');
            Assert::false(str_contains($e->getMessage(), 's3cret-token'));
        }
    }

    public function masksTheEndpointUrlWhenTheDispatcherThrows(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://svc:p4ssw0rd@hooks.example.com/e', secret: 'secret');

        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(): never => throw new \RuntimeException('Connection refused'),
        );

        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: new ConfigWebhookEndpointProvider(map: ['order.created' => [$endpoint]]),
            deliveryStorage: $this->storage,
        );

        try {
            $publisher->publish($this->makeMessage(type: 'order.created'));
            Assert::fail('Expected PublishException');
        } catch (PublishException $e) {
            Assert::string($e->getMessage())->contains('https://svc:***@hooks.example.com/e');
            Assert::false(str_contains($e->getMessage(), 'p4ssw0rd'));
        }
    }

    public function scrubsTheCredentialOutOfTheUpstreamDeliveryError(): void
    {
        // The endpoint this class interpolates is masked, but getLastError() is
        // text the dispatcher wrote, and a PSR-18 client puts the whole request
        // URI into it. Appending it verbatim put the credential back in the log.
        $url = 'https://hooks.example.com/e?access_token=s3cret-token';
        $endpoint = new WebhookEndpoint(url: $url, secret: 'secret');

        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
                => WebhookDelivery::create(event: $event, endpoint: $endpoint)
                    ->withAttempt(new DateTimeImmutable(), 'cURL error 7: Failed to connect for ' . $url)
                    ->withStatus(WebhookDeliveryStatus::Failed),
        );

        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: new ConfigWebhookEndpointProvider(map: ['order.created' => [$endpoint]]),
            deliveryStorage: $this->storage,
        );

        try {
            $publisher->publish($this->makeMessage(type: 'order.created'));
            Assert::fail('Expected PublishException');
        } catch (PublishException $e) {
            Assert::false(str_contains($e->getMessage(), 's3cret-token'));
            Assert::string($e->getMessage())
                ->contains('cURL error 7: Failed to connect')
                ->contains('hooks.example.com');
        }
    }

    public function scrubsTheCredentialOutOfTheThrownExceptionMessage(): void
    {
        $url = 'https://svc:p4ssw0rd@hooks.example.com/e';
        $endpoint = new WebhookEndpoint(url: $url, secret: 'secret');

        $dispatcher = (new FakeWebhookDispatcher())->whenDispatch(
            static fn(): never => throw new \RuntimeException('cURL error 6: Could not resolve host for ' . $url),
        );

        $publisher = new OutboxWebhookPublisher(
            dispatcher: $dispatcher,
            endpointProvider: new ConfigWebhookEndpointProvider(map: ['order.created' => [$endpoint]]),
            deliveryStorage: $this->storage,
        );

        try {
            $publisher->publish($this->makeMessage(type: 'order.created'));
            Assert::fail('Expected PublishException');
        } catch (PublishException $e) {
            Assert::false(str_contains($e->getMessage(), 'p4ssw0rd'));
            Assert::string($e->getMessage())
                ->contains('cURL error 6: Could not resolve host')
                ->contains('hooks.example.com');
        }
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
