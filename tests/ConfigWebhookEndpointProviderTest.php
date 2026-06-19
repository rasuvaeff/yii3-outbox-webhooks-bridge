<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;

#[CoversClass(ConfigWebhookEndpointProvider::class)]
final class ConfigWebhookEndpointProviderTest extends TestCase
{
    #[Test]
    public function returnsEndpointsForKnownType(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://example.com/hooks', secret: 'secret');
        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$endpoint]]);

        $this->assertSame([$endpoint], $provider->getEndpointsForType('order.created'));
    }

    #[Test]
    public function returnsEmptyArrayForUnknownType(): void
    {
        $provider = new ConfigWebhookEndpointProvider();

        $this->assertSame([], $provider->getEndpointsForType('order.created'));
    }

    #[Test]
    public function returnsMultipleEndpointsForOneType(): void
    {
        $ep1 = new WebhookEndpoint(url: 'https://a.example.com/hook', secret: 'secret-a');
        $ep2 = new WebhookEndpoint(url: 'https://b.example.com/hook', secret: 'secret-b');
        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$ep1, $ep2]]);

        $this->assertSame([$ep1, $ep2], $provider->getEndpointsForType('order.created'));
    }

    #[Test]
    public function isolatesEndpointsByType(): void
    {
        $ep1 = new WebhookEndpoint(url: 'https://a.example.com/hook', secret: 's1');
        $ep2 = new WebhookEndpoint(url: 'https://b.example.com/hook', secret: 's2');
        $provider = new ConfigWebhookEndpointProvider(map: [
            'order.created' => [$ep1],
            'order.paid' => [$ep2],
        ]);

        $this->assertSame([$ep1], $provider->getEndpointsForType('order.created'));
        $this->assertSame([$ep2], $provider->getEndpointsForType('order.paid'));
        $this->assertSame([], $provider->getEndpointsForType('order.cancelled'));
    }
}
