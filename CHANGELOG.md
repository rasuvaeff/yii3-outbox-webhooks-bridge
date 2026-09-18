# Changelog

## 1.0.6 — 2026-09-18

### Fixed

- README showed `$processor->process(types: [...])`, an argument
  `Rasuvaeff\Yii3Outbox\Processor::process()` does not take, and omitted the
  required `retryPolicy`. The example now compiles, and a new section
  "Sharing an outbox with other consumers" states what the removed argument
  was hiding: `Processor` claims every type, and `OutboxWebhookPublisher`
  acknowledges a message with no configured endpoints as published — on a
  shared outbox that silently drops messages meant for another consumer.
  The section shows the two ways to keep the publisher away from types it
  does not own (#18).
- `composer.json` declares `extra.branch-alias` (`dev-master` → `1.x-dev`)
  so the family's config-merge harness can resolve the package from a path
  repository.

## 1.0.5 — 2026-08-29

### Fixed

- Widen `rasuvaeff/yii3-webhooks` to `^1.0 || ^2.0`. The constraint was `^1.0`,
  which silently locked consumers out of the webhooks core's 2.0.0 security
  release (length-prefixed signature format, SSRF-hardened `WebhookEndpoint`)
  as long as they kept the bridge installed. The bridge consumes the same API
  on both lines — every interface and value object it touches
  (`WebhookDispatcher`, `WebhookDeliveryStorage`, `WebhookEvent`,
  `WebhookEndpoint(url:, secret:)`, `WebhookDelivery::create()`) is
  shape-identical across 1.x and 2.0; the test suite runs against both
  (the `Prefer lowest` CI job pins 1.x) (#16).

## 1.0.4 — 2026-08-20

### Fixed

- Mask endpoint URLs in `PublishException` messages. The message goes into the
  outbox `Processor` log verbatim, so an endpoint carrying a credential in its
  query string or userinfo component leaked that credential into every log sink
  the application has. Scheme, host, port, path and query keys survive; query
  values, the password and the fragment are replaced with `***`
  ([#13](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/issues/13)).
- Scrub the endpoint's secrets out of the upstream error text as well. Only the
  URL this package interpolates was masked; `WebhookDelivery::getLastError()`
  and the message of whatever the dispatcher threw were appended verbatim, and a
  PSR-18 client routinely puts the whole request URI into them
  (`cURL error 7: ... for https://host/e?access_token=…`). The credential
  therefore still reached the log in the most common configuration, and the
  tests missed it because both credential cases used upstream errors carrying no
  URL at all (`HTTP 500`, `Connection refused`)
  ([#13](https://github.com/rasuvaeff/yii3-outbox-webhooks-bridge/issues/13)).

### Added

- `rasuvaeff/property-testing-testo` covers the masker with properties: no
  generated secret survives masking in any of its three placements, the host
  always does, masking is idempotent, and it never throws for an arbitrary
  string. `scrub()` gets the same treatment: a generated secret never survives,
  wherever the upstream text quoted it, and scrubbing is idempotent.

### Changed

- Development tooling: `rasuvaeff/rector-named-literals` is part of the rector
  set, the mutation job gets its own narrow change filter, and the property
  regression corpus is cached between CI runs.
- Raise the Infection gate from `minMsi` 88 to 95. The suite now scores 96.15%,
  and the two surviving mutants are equivalent (removing a `return` whose
  fallthrough produces the same result), so 95 is the honest ceiling minus
  headroom.

## 1.0.3 — 2026-07-26

- Document the all-or-nothing fan-out retry as a contract, not a table row. When
  one of several endpoints fails, the outbox retries the whole message and every
  already-successful endpoint is delivered to again. The consequences were
  undocumented: receiver-side dedup on the event id is mandatory rather than
  advisory, and a unique constraint on `(event_id, endpoint_url)` in
  `WebhookDeliveryStorage` turns a normal retry into a duplicate-key error.
- Document that `occurredAt` is the outbox message's `createdAt`, so a receiver
  measuring an SLA from it measures from the event, not from the delivery
  attempt — a backlogged outbox makes healthy deliveries look overdue.
- Document that `WebhookRetryPolicy` from `yii3-webhooks` is unused here: retry
  belongs to the outbox `Processor`, and configuring both gives one event two
  schedules.

## 1.0.2 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-19

- `WebhookEndpointProvider` — interface for resolving `WebhookEndpoint` list by outbox message type.
- `ConfigWebhookEndpointProvider` — array-backed implementation for static configuration.
- `OutboxWebhookPublisher` — implements `PublisherInterface`: converts each `OutboxMessage` to a `WebhookEvent`, dispatches it to all configured endpoints, saves delivery records, and throws `PublishException` on any failure so the outbox `Processor` can retry.

