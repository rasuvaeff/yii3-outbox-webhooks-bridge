<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge;

use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;

/**
 * Endpoint provider backed by a plain PHP array. Suitable for static
 * configuration — use a database-backed provider when endpoints change at
 * runtime.
 *
 * @api
 */
final readonly class ConfigWebhookEndpointProvider implements WebhookEndpointProvider
{
    /**
     * @param array<string, list<WebhookEndpoint>> $map  message-type → endpoints
     */
    public function __construct(
        private array $map = [],
    ) {}

    #[\Override]
    public function getEndpointsForType(string $type): array
    {
        return $this->map[$type] ?? [];
    }

    /**
     * The message types that have at least one endpoint — what a `Processor`
     * over a shared storage should be scoped to, so it never claims a message
     * this publisher would acknowledge with zero deliveries.
     *
     * @return list<string>
     */
    public function configuredTypes(): array
    {
        $types = [];

        foreach ($this->map as $type => $endpoints) {
            if ($endpoints !== []) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
