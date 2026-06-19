# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — 2026-06-19

- `WebhookEndpointProvider` — interface for resolving `WebhookEndpoint` list by outbox message type.
- `ConfigWebhookEndpointProvider` — array-backed implementation for static configuration.
- `OutboxWebhookPublisher` — implements `PublisherInterface`: converts each `OutboxMessage` to a `WebhookEvent`, dispatches it to all configured endpoints, saves delivery records, and throws `PublishException` on any failure so the outbox `Processor` can retry.
