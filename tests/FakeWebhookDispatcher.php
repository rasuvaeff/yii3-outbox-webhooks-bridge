<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge\Tests;

use Closure;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

/**
 * @internal
 */
final class FakeWebhookDispatcher implements WebhookDispatcher
{
    private ?Closure $dispatchCallback = null;
    private int $dispatchCount = 0;

    public function whenDispatch(Closure $callback): self
    {
        $this->dispatchCallback = $callback;

        return $this;
    }

    #[\Override]
    public function dispatch(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
    {
        $this->dispatchCount++;

        if ($this->dispatchCallback !== null) {
            return ($this->dispatchCallback)($event, $endpoint);
        }

        return WebhookDelivery::create(event: $event, endpoint: $endpoint)
            ->withStatus(\Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus::Delivered);
    }

    public function getDispatchCount(): int
    {
        return $this->dispatchCount;
    }
}
