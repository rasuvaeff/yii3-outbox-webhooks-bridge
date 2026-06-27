<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge\Tests;

use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConfigWebhookEndpointProvider::class)]
final class ConfigWebhookEndpointProviderTest
{
    public function returnsEndpointsForKnownType(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://example.com/hooks', secret: 'secret');
        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$endpoint]]);

        Assert::same($provider->getEndpointsForType('order.created'), [$endpoint]);
    }

    public function returnsEmptyArrayForUnknownType(): void
    {
        $provider = new ConfigWebhookEndpointProvider();

        Assert::same($provider->getEndpointsForType('order.created'), []);
    }

    public function returnsMultipleEndpointsForOneType(): void
    {
        $ep1 = new WebhookEndpoint(url: 'https://a.example.com/hook', secret: 'secret-a');
        $ep2 = new WebhookEndpoint(url: 'https://b.example.com/hook', secret: 'secret-b');
        $provider = new ConfigWebhookEndpointProvider(map: ['order.created' => [$ep1, $ep2]]);

        Assert::same($provider->getEndpointsForType('order.created'), [$ep1, $ep2]);
    }

    public function isolatesEndpointsByType(): void
    {
        $ep1 = new WebhookEndpoint(url: 'https://a.example.com/hook', secret: 's1');
        $ep2 = new WebhookEndpoint(url: 'https://b.example.com/hook', secret: 's2');
        $provider = new ConfigWebhookEndpointProvider(map: [
            'order.created' => [$ep1],
            'order.paid' => [$ep2],
        ]);

        Assert::same($provider->getEndpointsForType('order.created'), [$ep1]);
        Assert::same($provider->getEndpointsForType('order.paid'), [$ep2]);
        Assert::same($provider->getEndpointsForType('order.cancelled'), []);
    }
}
