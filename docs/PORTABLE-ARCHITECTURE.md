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
- **Remaining extraction work:** `RetentionService` now owns policy-window calculation and optional-module gating, while `RetentionRepository` / `PdoRetentionRepository` own dependency-ordered cleanup in one transaction. `HealthService` composes runtime/schema readiness and queue diagnostics through a read-only `HealthRepository`; `PdoHealthRepository` owns the MySQL queries. `GuestSessionService` owns guest/session ID and token generation, rate-limit consumption and persistence orchestration through `ChatRepository`, while the HTTP route retains request validation and response mapping. `ChatMessageService` owns visitor send/poll orchestration, rate-limit consumption, outbound eligibility and response composition through the same repository contract. The optional staff inbox has a separate MySQL/MariaDB repository, provider-neutral HMAC assertion authentication with one-time nonce protection, local role/assignment authorization service and versioned API routes; a WordPress assertion proxy and UI remain unimplemented. Webhook inbox SQL/leases, WhatsApp provider-event persistence and WhatsApp outbox enqueue/claim/consent-check/cancel/complete/retry/failure transitions are repository-backed. Meta signup-state consumption, active connection persistence/lookup, token refresh writes and expiry transitions now use a dedicated `MetaConnectionRepository`; provider payload interpretation remains an application-service concern.
- Move one vertical slice at a time (sessions/messages, consent/rate limits, webhook inbox, then provider-event persistence). Keep payload interpretation and unrelated worker domains separate until their own contracts are extracted.
- Add transaction and concurrency tests before implementing a second database.
- Implement PostgreSQL only after the repository boundary is proven by tests.
- Add other dialects only when there is a concrete supported implementation and CI coverage.

### Additional backend runtimes

- Reimplement the same API contract in another runtime only when justified.
- Reuse shared contract fixtures and security acceptance tests.
- Do not fork business rules between implementations.

## Current repository facts

The active PHP application uses PDO and MySQL/MariaDB-oriented SQL. Its configuration contains a PDO DSN and credentials. The MySQL/MariaDB `PdoChatRepository` owns guest-session creation, token lookup/touch, visitor-message creation, message polling, delivery-status reads, WhatsApp consent transitions, fixed-window rate-limit buckets, and encrypted webhook-inbox enqueue/claim/complete/failure transitions. The message insert, conversation activity update and optional WhatsApp outbox insert remain atomic; guest/session creation and initial consent remain atomic. `GuestSessionService` creates hash-only session credentials and maps exhausted rate limits to a domain exception. `ChatMessageService` owns message send/poll use cases, idempotency conflict propagation and message rate-limit signaling. Webhook inbox payload encryption and durable processing now live in dedicated application services; active Meta connection lookup is exposed through `MetaConnectionRepository` (the webhook worker also retains its existing `ChatRepository` lookup contract for its runtime dependency). The repository owns provider-event deduplication, inbound/echo message insertion, conversation activity updates, status-event persistence and delivery-state reconciliation. Retention policy and cleanup now use `RetentionService` and `PdoRetentionRepository`; the CLI entry point only adapts process I/O and logging. Health schema inspection and queue counts now use `HealthService` and `PdoHealthRepository`; the public route only handles HTTP concerns and safe response serialization. Optional staff inbox persistence and policy have separate MySQL/MariaDB repository, HMAC assertion and authorization service boundaries; API routes and UI are not implemented. The WhatsApp outbox worker now delegates all job persistence and lease transitions to the repository; provider HTTP I/O remains outside database transactions. This is a deliberately partial extraction, not a complete storage-adapter architecture.

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

The MySQL/MariaDB `PdoChatRepository` owns visitor session/message persistence, WhatsApp consent transitions, fixed-window rate-limit buckets, encrypted webhook-inbox persistence/leases, provider-event deduplication, inbound/Business App echo message persistence, status-event persistence and delivery-state reconciliation. The separate `PdoMetaConnectionRepository` owns Meta signup-state and connected-account lifecycle persistence. Meta payload interpretation remains in its processor service; webhook payload encryption and inbox processing are separate services. Embedded Signup state/connection persistence and token lifecycle transitions are repository-backed, while `MetaEmbeddedSignupService` now owns the higher-level workflow behind injected repository, cipher, Graph client and configuration dependencies; procedural functions remain compatibility facades.

This remains a partial extraction: retention is now isolated behind a service and repository contract, but the adapter is still MySQL/MariaDB-specific. Optional staff inbox persistence has a separate MySQL/MariaDB repository, HMAC signed-assertion authentication with one-time nonce replay protection, and local role/assignment authorization service; HTTP routes and UI remain future work. WhatsApp outbox-worker persistence has moved behind ChatRepository, and `WhatsAppOutboxWorker` now owns the application orchestration for claiming jobs, checking consent, invoking the provider and recording lease-fenced outcomes. Provider HTTP I/O remains outside transactions. The adapters are still MySQL/MariaDB-specific; this change does not establish PostgreSQL support.


### WhatsApp application service decomposition (2026-11-23)

The Meta Cloud API adapter, Meta webhook event mapper and WhatsApp outbox worker orchestration are now separate classes under `app/channels/whatsapp/`. `app/whatsapp.php` retains procedural compatibility facades for existing endpoints and CLI callers. The webhook processor depends on `ChatRepository` and handles inbound text, Business App echoes and status events; the outbox worker depends on `ChatRepository` plus `WhatsAppTextTransport`. Raw webhook signature verification, durable inbox acknowledgement/recovery and active Meta phone lookup retain their separate endpoint/repository boundaries; retention now runs through `RetentionService` and `PdoRetentionRepository`; operational health composition uses `HealthService` and `HealthRepository`.


### Durable webhook inbox service boundary (2026-11-23)

`WebhookPayloadCipher` preserves the existing AES-256-GCM key derivation and associated data so already-encrypted inbox rows remain readable. `WebhookInboxWorker` claims and decrypts jobs, validates the provider envelope, resolves the active Meta phone-number ID through `ChatRepository`, invokes the idempotent `MetaWebhookProcessor`, and completes or retries the lease. The worker has no PDO or HTTP dependency. Event-level deduplication makes a partially processed multi-event payload safe to replay; the inbox row is completed only after all events have been handled. HTTP signature verification and persist-before-ack remain in the public route, while the cron endpoint delegates to the same worker facade.


### Meta webhook verification boundary (2026-11-23)

`MetaWebhookSignatureVerifier` now owns HMAC-SHA256 verification over the exact raw request body and constant-time signature comparison. The public HTTP route still owns method handling, request-size enforcement, persistence-before-acknowledgement and HTTP response generation. This keeps provider cryptography testable without moving HTTP concerns into the domain processor.


### Meta webhook HTTP endpoint (2026-11-23)

`MetaWebhookEndpoint` now owns POST size/signature/JSON/envelope validation, encrypted inbox enqueue-before-acknowledgement and bounded inline processing. The route performs this validation before opening a database connection, so unauthenticated or malformed webhook traffic does not consume database connections. It returns a response description instead of writing headers or exiting. `public/api/webhook.php` remains the PHP transport adapter for raw-body input, HTTP response emission and failure logging. It invokes the endpoint's static GET handshake path before opening a database connection. The CLI recovery endpoint invokes the same inbox worker without going through the HTTP controller.


### Meta signup infrastructure boundaries (2026-11-23)

`MetaTokenCipher` owns Meta access-token AES-256-GCM encryption while preserving the existing derivation and AAD for stored-token compatibility. `MetaGraphApiClient` centralizes Graph API requests, restricts destinations to HTTPS `graph.facebook.com` versioned paths, disables redirects and rejects invalid bearer-token header values. `MetaConnectionRepository` and its PDO adapter own one-time signup-state consumption, active connection persistence/lookup, token refresh writes and expiry transitions. `MetaEmbeddedSignupService` owns the higher-level workflow behind injected repository, cipher and Graph API dependencies; the procedural Embedded Signup entry points are compatibility facades.


### Operational health service boundary (2026-11-24)

`HealthService` now owns the required-table readiness decision, runtime checks and queue-metric gating. `HealthRepository` defines the read-only persistence contract and `PdoHealthRepository` implements information-schema and queue-count queries for MySQL/MariaDB. The public health endpoint retains CORS, method negotiation, request IDs, HTTP status/JSON output and sanitized failure handling; it no longer contains SQL. This remains a MySQL/MariaDB adapter, not proof of alternate-database support.


### Guest session application service boundary (2026-11-24)

`GuestSessionService` now owns the guest-session use case: configured rate-limit consumption, UUID/token generation, token hashing, expiry calculation and repository-backed creation. The browser still receives the one-time random token; only its SHA-256 hash is persisted. The public route continues to own content-type/field/phone validation, CORS, method negotiation and mapping the rate-limit exception to HTTP 429 with `Retry-After`. No session schema or token format changed.


### Visitor message application service boundary (2026-11-24)

`ChatMessageService` now owns visitor send/poll use cases. It consumes the session-scoped rate limit, validates the idempotency key after that limit is consumed, determines whether the visitor's persisted WhatsApp consent and configuration permit an outbox job, delegates atomic message/outbox persistence to `ChatRepository`, and composes polling/delivery-update data. The HTTP route retains guest authentication, JSON/field/cursor validation, HTTP status mapping and safe error responses. Existing idempotent replay/conflict and delivery-state behavior is retained.


### Visitor consent application service boundary (2026-11-24)

`WhatsAppConsentService` now owns the visitor opt-in/opt-out use case. It requires a persisted normalized phone for opt-in, delegates the append-only consent transition to `ChatRepository`, and returns the resulting state timestamp. The HTTP route retains authentication, JSON validation and mapping of the missing-phone domain signal to HTTP 422. Consent state, audit history, identity-link deactivation and queued-send cancellation remain implemented transactionally by the repository.


### Versioned API contract boundary (2026-11-24)

The core v1 widget/operator surface is described by `docs/openapi.yaml` (OpenAPI 3.1): session creation, message send/poll, consent, health, Meta webhook and administrator Embedded Signup. The optional staff API is described separately by `docs/openapi-staff.yaml`, including all ten implemented staff routes and the HMAC assertion contract. The legacy `/api/*.php` and versioned `/api/v1/*.php` aliases still share the same handlers. CI runs a dependency-free structural validator for both documents, checking version, route coverage, operation IDs, response declarations, request-body content, internal references and required security schemes. Reusable request/response fixtures, full schema conformance and cross-backend acceptance tests are still outstanding. This document does not imply PostgreSQL parity or shared-database multi-tenancy.


### Backend-neutral API acceptance runner (2026-11-24)

`tests/api-acceptance.mjs` exercises the public v1 HTTP contract using only Node's built-in fetch and crypto APIs. It does not connect to MySQL or inspect implementation internals. The runner creates a guest session and verifies one-time credential shape, visitor message persistence, idempotent replay and conflict behavior, invalid-token rejection, polling/cursor output, opt-in phone preconditions and opt-out. `DIMDIAN_API_BASE_URL` can point the same suite at another backend implementing the documented v1 routes. This is the first reusable black-box acceptance slice; staff API conformance and cross-database migration/transaction behavior remain covered by the PHP/MySQL-specific suites until an alternate backend exists.


### Backend-neutral staff API acceptance runner (2026-11-24)

`tests/staff-api-acceptance.mjs` exercises the optional staff API without direct database access. It signs each request with a fresh HMAC assertion bound to the exact method, URI, body digest and nonce, provisions first-seen local agent identities through the identity endpoint, and creates visitor conversations through the public API. It verifies unassigned visibility, self-assignment, cross-agent isolation, private-note idempotency and separation, website-only staff replies, assignment history, administrator-only operation denial and access revocation after unassignment. Both backend-neutral runners are configured to accept a different API base URL and can serve as the first black-box compatibility gate for another runtime. The current CI run remains against PHP/MySQL only.


### Tenant isolation boundary (design only; 2026-11-24)

The current core and optional staff DDL contain no tenant root or tenant keys, and the repository contracts do not accept tenant context. The public widget also has no public tenant/widget identifier. The implementation must therefore remain one business per database. The proposed end-to-end tenant model, schema ownership inventory, webhook routing exception, composite relational constraints, migration sequence and negative acceptance tests are documented in [TENANCY-ARCHITECTURE.md](TENANCY-ARCHITECTURE.md). This is a design artifact only; no tenant columns, tenant resolver or shared-database behavior have been implemented by this change.


### Storage capability interfaces (2026-11-24)

The aggregate `ChatRepository` contract is now composed from narrower interfaces: `GuestSessionRepository`, `RateLimitRepository`, `VisitorMessageRepository`, `ConsentRepository`, `WhatsAppIdentityRepository`, `WebhookInboxRepository`, `ProviderEventRepository` and `WhatsAppOutboxRepository`. Focused composition contracts serve session operations, visitor messaging and webhook processing. Application services now depend on the narrow capability they actually use, while `ChatRepository` remains the compatibility aggregate implemented by the existing MySQL/MariaDB adapter. This reduces adapter coupling but does not itself add another database engine or tenant scope.


### Physical adapter decomposition

The first capability now has a dedicated physical adapter: `PdoWebhookInboxRepository` owns encrypted webhook inbox enqueue, lease claiming/reclaim, completion and retry/failure persistence. `PdoChatRepository` remains the compatibility aggregate and delegates these methods using the same PDO connection. Other chat persistence operations still reside in `PdoChatRepository`; interface segregation should not be mistaken for complete physical adapter decomposition or PostgreSQL support.


The fixed-window rate-limit capability now also has a dedicated physical adapter, `PdoRateLimitRepository`. It owns bucket hashing, window reset, atomic increment/check and transaction handling. `PdoChatRepository` delegates the aggregate method to this adapter using the same PDO connection. This is an incremental extraction; the rest of the chat SQL is not yet fully decomposed.


The consent capability now has its own `PdoConsentRepository`. It owns the atomic transition across the consent ledger, guest opt-in state, WhatsApp identity links and cancellation of queued outbound jobs. The aggregate delegates on the same PDO connection, preserving nested transaction participation when initial consent is recorded as part of guest-session creation.


Guest/session persistence now has a dedicated `PdoGuestSessionRepository` for guest, conversation and session creation, token-hash lookup and last-seen updates. It shares the `PdoConsentRepository` and same PDO connection so initial opt-in is still atomic with session creation. The aggregate retains the legacy interface and delegates those operations.


Visitor-visible message persistence now has a dedicated `PdoVisitorMessageRepository` for message creation, idempotency, atomic optional outbox insertion, polling and delivery updates. The adapter retains the message-plus-outbox transaction and the aggregate delegates using the same PDO connection.


Provider-event persistence now has a dedicated `PdoProviderEventRepository` for provider-event deduplication, inbound message routing/insertion, ignored events, status-event storage and delivery-state reconciliation. The aggregate delegates these operations while retaining a compatibility method used by outbox completion to reconcile provider callbacks that arrive before send acceptance is persisted.


The WhatsApp outbox now has a dedicated `PdoWhatsAppOutboxRepository` for enqueue, claim/reclaim, consent checks, cancellation, completion and failure transitions. It receives the provider-event capability so an early status callback can be reconciled inside the send-completion transaction. Provider HTTP requests remain outside transactions in the worker; visitor-message plus initial outbox insertion remains atomic in `PdoVisitorMessageRepository`.


Active WhatsApp phone-number lookup is now implemented once in `PdoMetaConnectionRepository`. The legacy `PdoChatRepository::activeWhatsAppPhoneNumberId()` method delegates to that adapter to preserve the `WhatsAppIdentityRepository` capability used by webhook composition.
