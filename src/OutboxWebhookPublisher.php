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
 * Endpoint URLs are put into the {@see PublishException} message through
 * {@see UrlMasker}: the failure message ends up in the worker log, and an
 * endpoint may carry a credential in its query string.
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
                    $failures[] = self::describeFailure(
                        $endpoint->getUrl(),
                        $delivery->getLastError() ?? 'unknown error',
                    );
                }
            } catch (\Throwable $e) {
                $failures[] = self::describeFailure($endpoint->getUrl(), $e->getMessage());
            }
        }

        if ($failures !== []) {
            throw new PublishException(
                message: sprintf('Webhook delivery failed for %d endpoint(s): %s', count($failures), implode('; ', $failures)),
                outboxMessage: $message,
            );
        }
    }

    /**
     * The upstream half of the line is text this class did not write: a
     * delivery's `getLastError()`, or the message of whatever the dispatcher
     * threw. A PSR-18 client puts the whole request URI into that message, so
     * it is scrubbed rather than trusted — masking only the URL interpolated
     * here would leave the credential in the log anyway.
     */
    private static function describeFailure(string $url, string $error): string
    {
        return sprintf('%s: %s', UrlMasker::mask($url), UrlMasker::scrub($error, $url));
    }
}
