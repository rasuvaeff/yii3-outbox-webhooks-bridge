<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge\Benchmarks;

use Rasuvaeff\Yii3OutboxWebhooksBridge\ConfigWebhookEndpointProvider;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Testo\Bench;

final class BridgeBench
{
    #[Bench(
        callables: [
            'five-endpoints' => [self::class, 'lookupFiveEndpoints'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function lookupOneEndpoint(): array
    {
        $provider = new ConfigWebhookEndpointProvider(
            map: [
                'order.created' => [
                    new WebhookEndpoint(url: 'https://example.com/hook', secret: 'secret1'),
                ],
            ],
        );

        return $provider->getEndpointsForType('order.created');
    }

    public static function lookupFiveEndpoints(): array
    {
        $provider = new ConfigWebhookEndpointProvider(
            map: [
                'order.created' => [
                    new WebhookEndpoint(url: 'https://a.example.com/hook', secret: 'secret1'),
                    new WebhookEndpoint(url: 'https://b.example.com/hook', secret: 'secret2'),
                    new WebhookEndpoint(url: 'https://c.example.com/hook', secret: 'secret3'),
                    new WebhookEndpoint(url: 'https://d.example.com/hook', secret: 'secret4'),
                    new WebhookEndpoint(url: 'https://e.example.com/hook', secret: 'secret5'),
                ],
            ],
        );

        return $provider->getEndpointsForType('order.created');
    }
}
