# AGENTS.md — yii3-outbox-webhooks-bridge

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-outbox-webhooks-bridge` bridges `yii3-outbox` and `yii3-webhooks`
to deliver durable at-least-once webhook notifications. It converts each
`OutboxMessage` into a `WebhookEvent` and dispatches it to configured endpoints
via an injected `WebhookDispatcher`. Namespace:
`Rasuvaeff\Yii3OutboxWebhooksBridge`.

Public API:
- `WebhookEndpointProvider` — interface: returns `list<WebhookEndpoint>` for a
  message type
- `ConfigWebhookEndpointProvider` — array-backed implementation for static config
- `OutboxWebhookPublisher` — implements `PublisherInterface`; the bridge core

The package provides no HTTP client and no Yii3 DI config — wiring is the
application's responsibility, and each app has its own `WebhookDispatcher`
implementation (PSR-18-based or custom).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Fanout is all-or-nothing per outbox message.** If any endpoint delivery
   fails, `OutboxWebhookPublisher::publish()` throws `PublishException` so the
   outbox `Processor` can retry the whole message. Receivers must be
   idempotent (use the event id for dedup). Never silently swallow failures.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- `OutboxMessage.id` → `WebhookEvent.id` (used for dedup on the receiver side).
- No endpoints for a type → silent success (zero deliveries, message marked
  published by the outbox `Processor`).
- Failed delivery status or dispatcher exception → `PublishException`; failed
  deliveries are still saved to `WebhookDeliveryStorage` before the exception
  propagates, so a retry-runner can act on them independently.
- This package does NOT bind `PublisherInterface` in a DI config — do not add
  `config/di.php`. The app wires the publisher to the outbox `Processor`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
