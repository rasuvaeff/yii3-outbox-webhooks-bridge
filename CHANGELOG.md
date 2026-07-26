# Changelog

## Unreleased

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

