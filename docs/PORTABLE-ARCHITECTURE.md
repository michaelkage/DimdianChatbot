# ChatBridge portability architecture

## Decision status

- Status: Accepted product direction; implementation is incremental.
- Applies to: the widget, backend API, persistence, channels, integrations and deployment.
- Current PHP reference implementation: PHP + PDO + MySQL-compatible SQL.
- Current hosted demonstration backend: Supabase Edge Functions.
- Neither implementation is mandatory for future customers.

## Product definition

ChatBridge is a modular customer communication platform. It provides an embeddable chat widget, a backend API, optional storage and deployment adapters, optional communication channels, optional AI providers, and platform-specific business integrations.

WordPress and WooCommerce remain the first major distribution and commerce integrations. They are not the product boundary.

## Non-negotiable boundaries

1. The browser widget talks only to a configured ChatBridge API endpoint.
2. The browser never receives database credentials, Meta access tokens, AI provider secrets, or administrative credentials.
3. The backend owns tenant resolution, authentication, authorization, validation, consent, rate limits, persistence and channel orchestration.
4. Database-specific SQL and connection details must not leak into the widget, channel providers, or platform connectors.
5. WhatsApp, AI, WooCommerce and WordPress are optional capabilities. Website-only human support must remain a valid configuration.
6. Public widget keys identify a public tenant/widget configuration; they do not authorize access to private conversations or management operations.
7. Origin allowlists are an additional browser control, not a replacement for authentication or authorization.
8. A self-hosted deployment must retain core runtime functionality without depending on the ChatBridge management portal.
9. Implementations claiming API compatibility must pass the same contract tests.
10. Data export and migration must be designed into the storage boundary rather than left to a future proprietary export format.

## Logical components

### Widget

The widget is a dependency-light browser client distributed through a script embed, self-hosted assets, and eventually an NPM package. It receives a public API base URL and public tenant/widget identifier. It must not know which backend language, hosting provider, or database is behind that API.

### Backend API

The backend is the authoritative application boundary. It owns guest sessions, conversation access, message validation, consent, tenant isolation, rate limiting, attachments, staff operations, channel handover and provider orchestration.

The existing PHP endpoints are the current implementation, not the permanent API design. The existing endpoint contract is documented in API.md. A future versioned API must be introduced with compatibility mapping rather than silently breaking existing widget and WordPress installations.

### Storage adapters

Storage adapters isolate persistence from application services. A complete adapter must implement the same repository operations and transaction guarantees, not merely provide a database connection.

Initial target families:
- MySQL
- MariaDB
- PostgreSQL
- PostgreSQL-compatible hosted services such as Neon and Supabase
- SQLite for constrained/local use where concurrency and operational requirements are satisfied
- Additional databases through independently tested adapters

A database product being PostgreSQL-compatible does not automatically make it a separately implemented adapter. For example, Neon and Supabase can use the PostgreSQL adapter unless a provider-specific capability is deliberately enabled.

The current schema and query implementation use MySQL/MariaDB-oriented SQL, including MySQL-specific DDL and query constructs. PostgreSQL and SQLite compatibility must not be claimed until dialect-specific migrations, repositories and integration tests exist.

### Channel providers

Channels implement a provider boundary for sending, receiving, validating webhooks and mapping provider events to ChatBridge conversations. WhatsApp is the first channel. Provider credentials are stored and used only server-side.

### Intelligence providers

AI providers are optional. A provider adapter accepts a normalized conversation context and returns a normalized result. The core must not depend on a single model vendor or expose provider credentials to the widget.

### Platform integrations

WordPress and WooCommerce are integration adapters. They may provide widget installation, public catalogue lookup, and verified business workflows. They must not become dependencies of the core API.

### Deployment

The same backend contract may be implemented in PHP, Node.js or another suitable runtime. Deployment targets may include shared PHP hosting, VPS, containers and serverless platforms. A deployment target is supported only when its runtime, storage, background-job and webhook requirements are actually satisfied.

## Deployment models

| Model | Runtime ownership | Data ownership |
|---|---|---|
| Managed | ChatBridge operates the backend | Managed by ChatBridge under the customer's selected plan |
| Self-hosted | Customer operates the backend | Customer selects and operates supported storage |
| Hybrid | Split by explicitly supported component boundaries | Customer and ChatBridge responsibilities are documented per component |

A self-hosted installation must not require a central control-plane request for every chat message.

## API compatibility strategy

- Preserve the existing PHP /api/*.php endpoints as the legacy/current API while the widget depends on them.
- Define a versioned canonical API contract before introducing additional backend implementations.
- Specify request/response schemas, authentication, error codes, idempotency, pagination, timestamps, webhook semantics and security requirements.
- Keep provider-specific fields out of generic conversation and message objects unless represented as explicit metadata.
- Maintain contract fixtures that can be run against every backend implementation.
- Provide an adapter or migration window for existing widget and WordPress clients.
- Do not label an implementation compatible merely because its endpoints return HTTP 200.

The first canonical API version should use resource-oriented paths under /api/v1, but routes become normative only when the specification and implementation tests are committed together.

## Incremental migration plan

### Foundation (current milestone)

- Record the product architecture and boundaries.
- Separate current implementation facts from target capabilities.
- Preserve the working PHP/MySQL pilot and existing production-hardening work.
- Establish compatibility acceptance criteria before changing persistence.
- Keep Supabase as the existing demo backend, not a mandatory dependency.

### API contract

- Define canonical /api/v1 operations and OpenAPI schemas.
- Map existing PHP endpoints to the canonical resources.
- Add contract fixtures for success, validation, authorization, idempotency and pagination.
- Version the widget's API client and preserve legacy operation during migration.

### Storage extraction

- Extract application services from direct PDO/SQL usage behind repository interfaces.
- Keep the current MySQL/MariaDB implementation as the first reference adapter.
- **Implemented first slice:** `ChatRepository` / `PdoChatRepository` now own guest-session creation and lookup, visitor-message creation, polling and delivery-state reads. The message, conversation activity and optional WhatsApp outbox writes are atomic; guest creation and initial opt-in are atomic.
- **Still coupled:** retention still uses PDO directly. The optional staff inbox has a separate MySQL/MariaDB repository, provider-neutral HMAC assertion authentication with one-time nonce protection, and local role/assignment authorization service; API routes, a WordPress assertion proxy and UI remain unimplemented. Webhook inbox SQL/leases, WhatsApp provider-event persistence and WhatsApp outbox enqueue/claim/consent-check/cancel/complete/retry/failure transitions are repository-backed; provider payload interpretation and active Meta connection lookup remain application-service concerns.
- Move one vertical slice at a time (sessions/messages, consent/rate limits, webhook inbox, then provider-event persistence). Keep payload interpretation and unrelated worker domains separate until their own contracts are extracted.
- Add transaction and concurrency tests before implementing a second database.
- Implement PostgreSQL only after the repository boundary is proven by tests.
- Add other dialects only when there is a concrete supported implementation and CI coverage.

### Additional backend runtimes

- Reimplement the same API contract in another runtime only when justified.
- Reuse shared contract fixtures and security acceptance tests.
- Do not fork business rules between implementations.

## Current repository facts

The active PHP application uses PDO and MySQL/MariaDB-oriented SQL. Its configuration contains a PDO DSN and credentials. The MySQL/MariaDB `PdoChatRepository` owns guest-session creation, token lookup/touch, visitor-message creation, message polling, delivery-status reads, WhatsApp consent transitions, fixed-window rate-limit buckets, and encrypted webhook-inbox enqueue/claim/complete/failure transitions. The message insert, conversation activity update and optional WhatsApp outbox insert remain atomic; guest/session creation and initial consent remain atomic. Webhook inbox payload encryption, Meta payload interpretation and active connection lookup remain application-service responsibilities. The repository owns provider-event deduplication, inbound/echo message insertion, conversation activity updates, status-event persistence and delivery-state reconciliation. Retention remains PDO-coupled. Optional staff inbox persistence and policy have separate MySQL/MariaDB repository, HMAC assertion and authorization service boundaries; API routes and UI are not implemented. The WhatsApp outbox worker now delegates all job persistence and lease transitions to the repository; provider HTTP I/O remains outside database transactions. This is a deliberately partial extraction, not a complete storage-adapter architecture.

The repository also contains a Supabase Edge Function demonstration backend and Meta webhook diagnostic functions. These are separate implementations and currently do not establish cross-backend API parity.

The public GitHub Pages demo currently runs the widget in browser-only demo mode. It is not a persistent production chat deployment.

The existing production-hardening branch includes consent lifecycle handling, durable webhook ingestion, Meta Embedded Signup operator functionality, retention processing and diagnostics. Those changes are preserved and are not proof that every roadmap capability is complete.

## Compatibility and release rule

A release may advertise a database, runtime, hosting target, channel, or platform integration only when:
1. its adapter or implementation exists;
2. its setup and migration instructions are documented;
3. its security and failure behaviour are tested; and
4. its compatibility tests run in CI or an explicitly documented external test environment.

No compatibility claim may be inferred solely from a shared protocol, compatible SQL dialect, or vendor marketing statement.


### Repository boundary progress (2026-11-21)

The MySQL/MariaDB repository now owns visitor session/message persistence, WhatsApp consent transitions, fixed-window rate-limit buckets, encrypted webhook-inbox persistence/leases, provider-event deduplication, inbound/Business App echo message persistence, status-event persistence and delivery-state reconciliation. Payload encryption, Meta payload interpretation and active Meta connection lookup remain application-service responsibilities.

This remains a partial extraction: retention still uses PDO directly. Optional staff inbox persistence has a separate MySQL/MariaDB repository, HMAC signed-assertion authentication with one-time nonce replay protection, and local role/assignment authorization service; HTTP routes and UI remain future work. Most WhatsApp outbox-worker persistence has now moved behind ChatRepository. The adapters are still MySQL/MariaDB-specific; this change does not establish PostgreSQL support.
