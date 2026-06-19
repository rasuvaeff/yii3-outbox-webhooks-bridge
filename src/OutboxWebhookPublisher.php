<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

/**
 * Implements {@see PublisherInterface} by converting each outbox message into
 * a {@see WebhookEvent} and dispatching it to every matching endpoint from the
 * {@see WebhookEndpointProvider}. Each dispatch result is persisted via
 * {@see WebhookDeliveryStorage}.
 *
 * If any endpoint delivery fails the method throws {@see PublishException} so
 * the outbox {@see \Rasuvaeff\Yii3Outbox\Processor} can retry later. Because
 * delivery is all-or-nothing per message, already-delivered endpoints receive
 * the event again on retry — use idempotency keys on the receiver side to
 * deduplicate.
 *
 * When no endpoints are configured for a message type the method succeeds
 * silently (the message is treated as published with zero deliveries).
 *
 * @api
 */
final readonly class OutboxWebhookPublisher implements PublisherInterface
{
    public function __construct(
        private WebhookDispatcher $dispatcher,
        private WebhookEndpointProvider $endpointProvider,
        private WebhookDeliveryStorage $deliveryStorage,
    ) {}

    #[\Override]
    public function publish(OutboxMessage $message): void
    {
        $endpoints = $this->endpointProvider->getEndpointsForType($message->getType());

        if ($endpoints === []) {
            return;
        }

        $event = new WebhookEvent(
            id: $message->getId(),
            type: $message->getType(),
            payload: $message->getPayload(),
            occurredAt: $message->getCreatedAt(),
        );

        $failures = [];

        foreach ($endpoints as $endpoint) {
            try {
                $delivery = $this->dispatcher->dispatch(event: $event, endpoint: $endpoint);
                $this->deliveryStorage->save(delivery: $delivery);

                if ($delivery->getStatus() === WebhookDeliveryStatus::Failed) {
                    $failures[] = sprintf(
                        '%s: %s',
                        $endpoint->getUrl(),
                        $delivery->getLastError() ?? 'unknown error',
                    );
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('%s: %s', $endpoint->getUrl(), $e->getMessage());
            }
        }

        if ($failures !== []) {
            throw new PublishException(
                message: sprintf('Webhook delivery failed for %d endpoint(s): %s', count($failures), implode('; ', $failures)),
                outboxMessage: $message,
            );
        }
    }
}
