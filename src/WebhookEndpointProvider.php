<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge;

use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;

/**
 * Returns the webhook endpoints that should receive delivery for a given
 * outbox message type. An empty list means the message type has no webhook
 * subscribers; the publisher treats this as a no-op and succeeds silently.
 *
 * @api
 */
interface WebhookEndpointProvider
{
    /**
     * @return list<WebhookEndpoint>
     */
    public function getEndpointsForType(string $type): array;
}
